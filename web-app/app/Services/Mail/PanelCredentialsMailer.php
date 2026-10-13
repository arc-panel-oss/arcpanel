<?php

namespace App\Services\Mail;

use App\Arc\SysConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The FTP credentials mail of web/edit/web/index.php and the "account
 * ready" mail of web/add/user/index.php: the e-mail template
 * data/templates/email/[LANG/]NAME.html when it exists, else
 * the built-in text; sender FROM_EMAIL / FROM_NAME (or noreply@HOST and
 * APP_NAME); subject SUBJECT_EMAIL. Sent through the server SMTP account
 * when USE_SERVER_SMTP is on, else the local sendmail. A failed mail does
 * not fail the change, as in the legacy page.
 */
final class PanelCredentialsMailer implements AccountMailer, CredentialsMailer, HostingCredentialsMailer, PasswordResetMailer
{
    public function __construct(
        private readonly SysConfig $config,
        private readonly string $installDir,
        private readonly LoggerInterface $log,
    ) {}

    public function sendFtpCredentials(string $to, string $domain, string $username, string $password, string $language): void
    {
        $appName = $this->config->get('APP_NAME', 'ArcPanel');
        $hostname = (string) gethostname();

        $template = $this->template('ftpaccount_created', $language);
        $subject = '';
        if ($template !== null) {
            if (preg_match('/<subject>(.*?)<\/subject>/si', $template, $matches) === 1) {
                $subject = str_replace(
                    ['{{hostname}}', '{{appname}}', '{{username}}', '{{domain}}'],
                    [$hostname, $appName, $username, $domain],
                    $matches[1],
                );
                $template = str_replace($matches[0], '', $template);
            }
        } else {
            $template = __("FTP account has been created and ready to use.\n\nHostname: {{domain}}\nUsername: {{username}}\nPassword: {{password}}\n\nBest regards,\n\n--\n{{appname}}");
        }
        if ($subject === '') {
            $subject = str_replace(
                ['{{subject}}', '{{hostname}}', '{{appname}}'],
                [sprintf(__('FTP Account Credentials: %s'), $username), $hostname, $appName],
                $this->config->get('SUBJECT_EMAIL', '{{subject}}') ?: '{{subject}}',
            );
        }

        $text = str_replace(
            ['{{domain}}', '{{username}}', '{{password}}', '{{appname}}'],
            [htmlentities($domain), htmlentities($username), htmlentities($password), $appName],
            $template,
        );

        $this->send($to, $subject, $text, 'FTP credentials mail failed');
    }

    public function sendAccountCredentials(string $to, string $username, string $name, string $password, string $language): void
    {
        $appName = $this->config->get('APP_NAME', 'ArcPanel');
        $hostname = (string) gethostname();
        $name = $name !== '' ? $name : $username;

        $template = $this->template('account_ready', $language);
        $subject = '';
        if ($template !== null) {
            if (preg_match('/<subject>(.*?)<\/subject>/si', $template, $matches) === 1) {
                $subject = str_replace(
                    ['{{hostname}}', '{{appname}}', '{{user}}', '{{name}}'],
                    [$hostname, $appName, $username, $name],
                    $matches[1],
                );
                $template = str_replace($matches[0], '', $template);
            }
        } else {
            $template = __("Hello {{name}},\n\nYour account has been created and ready to use.\n\nhttps://{{hostname}}/login/\nUsername: {{user}}\nPassword: {{password}}\n\nBest regards,\n\n--\n{{appname}}");
        }
        if ($subject === '') {
            $subject = str_replace(
                ['{{subject}}', '{{hostname}}', '{{appname}}'],
                [sprintf(__('Welcome to %s'), $appName), $hostname, $appName],
                $this->config->get('SUBJECT_EMAIL', '{{subject}}') ?: '{{subject}}',
            );
        }

        $text = str_replace(
            ['{{name}}', '{{user}}', '{{password}}', '{{hostname}}', '{{appname}}'],
            [htmlentities($name), htmlentities($username), htmlentities($password), htmlentities($hostname.':'.request()->getPort()), $appName],
            $template,
        );

        $this->send($to, $subject, $text, 'Account mail failed');
    }

    /**
     * web/reset: the "reset_password" template or the built-in text, with the
     * link of the e-mail on $host, the configured panel host (E7 batch 3
     * spec §11.2 as amended by E39 W2 §5.1). The code reaches no log.
     */
    public function sendPasswordReset(string $to, string $name, string $username, string $code, string $language, string $host): void
    {
        $appName = $this->config->get('APP_NAME', 'ArcPanel');
        $name = $name !== '' ? $name : $username;

        $template = $this->template('reset_password', $language);
        $subject = '';
        if ($template !== null) {
            if (preg_match('/<subject>(.*?)<\/subject>/si', $template, $matches) === 1) {
                $subject = strtr($matches[1], ['{{date}}' => date('Y-m-d H:i:s'), '{{hostname}}' => $host, '{{appname}}' => $appName, '{{user}}' => $username]);
                $template = str_replace($matches[0], '', $template);
            }
        } else {
            $template = __("Hello {{name}},\n\nTo reset your {{appname}} password, please follow this link:\nhttps://{{hostname}}/reset/?action=confirm&user={{user}}&code={{resetcode}}\n\nAlternatively, you may go to https://{{hostname}}/reset/?action=code&user={{user}} and enter the following reset code:\n{{resetcode}}\n\nIf you did not request password reset, please ignore this message and accept our apologies.\n\nBest regards,\n\n--\n{{appname}}");
        }
        if ($subject === '') {
            $subject = strtr($this->config->get('SUBJECT_EMAIL', '{{subject}}') ?: '{{subject}}', [
                '{{subject}}' => sprintf(__('Password Reset at %s'), date('Y-m-d H:i:s')), '{{hostname}}' => $host, '{{appname}}' => $appName,
            ]);
        }

        $text = strtr($template, [
            '{{name}}' => htmlentities($name), '{{appname}}' => $appName, '{{hostname}}' => $host,
            '{{user}}' => rawurlencode($username), '{{resetcode}}' => htmlentities($code),
        ]);

        $this->send($to, $subject, $text, 'Password reset mail failed');
    }

    /** web/add/db: the "database_credentials" template or the built-in text. */
    public function sendDatabaseCredentials(string $to, string $database, string $dbUser, string $password, string $dbAdmin, string $language): void
    {
        [$subject, $text] = $this->compose(
            'database_credentials',
            $language,
            // The legacy page spells the placeholder {{dabase}}; templates use it.
            ['{{dabase}}' => $database, '{{dbuser}}' => $dbUser],
            sprintf(__('Database Credentials: %s'), $database),
            __("Database has been created.\n\nDatabase: {{database}}\nUsername: {{username}}\nPassword: {{password}}\nSQL Manager: {{dbadmin}}\n\nBest regards,\n\n--\n{{appname}}"),
            ['database' => htmlentities($database), 'username' => htmlentities($dbUser), 'password' => htmlentities($password), 'dbadmin' => $dbAdmin],
        );

        $this->send($to, $subject, $text, 'Database credentials mail failed');
    }

    /** web/add/mail and web/edit/mail: the "email_credentials" template or the built-in text. */
    public function sendMailCredentials(string $to, array $details, string $language): void
    {
        [$subject, $text] = $this->compose(
            'email_credentials',
            $language,
            ['{{account}}' => htmlentities($details['account']), '{{domain}}' => htmlentities($details['domain'])],
            sprintf(__('Email Credentials: %s@%s'), htmlentities($details['account']), htmlentities($details['domain'])),
            __("Mail account has been created.\n\nCommon Account Settings:\nUsername: {{account}}@{{domain}}\nPassword: {{password}}\nWebmail: {{webmail}}\nHostname: {{hostname}}\n\nIMAP Settings\nAuthentication: Normal Password\nSSL/TLS: Port 993\nSTARTTLS: Port 143\nNo encryption: Port 143\n\nPOP3 Settings\nAuthentication: Normal Password\nSSL/TLS: Port 995\nSTARTTLS: Port 110\nNo encryption: Port 110\n\nSMTP Settings\nAuthentication: Normal Password\nSSL/TLS: Port 465\nSTARTTLS: Port 587\nNo encryption: Port 25\n\nBest regards,\n\n--\n{{appname}}"),
            [
                'domain' => htmlentities($details['domain']),
                'account' => htmlentities($details['account']),
                'password' => htmlentities($details['password']),
                'webmail' => $details['webmail'],
                'hostname' => $details['hostname'],
            ],
        );

        $this->send($to, $subject, $text, 'Mail credentials mail failed');
    }

    /**
     * Subject and body of a credentials mail (translate_email in
     * web/inc/main.php): the template's <subject> with $subjectVars, else
     * SUBJECT_EMAIL with $fallbackSubject; the template body, else
     * $fallbackBody, with {{name}} replaced from $bodyVars.
     *
     * @param  array<string, string>  $subjectVars  placeholder => value
     * @param  array<string, string>  $bodyVars  name => value
     * @return array{0: string, 1: string}
     */
    private function compose(string $name, string $language, array $subjectVars, string $fallbackSubject, string $fallbackBody, array $bodyVars): array
    {
        $appName = $this->config->get('APP_NAME', 'ArcPanel');
        $hostname = (string) gethostname();

        $template = $this->template($name, $language);
        $subject = '';
        if ($template !== null) {
            if (preg_match('/<subject>(.*?)<\/subject>/si', $template, $matches) === 1) {
                $subject = strtr($matches[1], ['{{hostname}}' => $hostname, '{{appname}}' => $appName] + $subjectVars);
                $template = str_replace($matches[0], '', $template);
            }
        } else {
            $template = $fallbackBody;
        }
        if ($subject === '') {
            $subject = strtr(
                $this->config->get('SUBJECT_EMAIL', '{{subject}}') ?: '{{subject}}',
                ['{{subject}}' => $fallbackSubject, '{{hostname}}' => $hostname, '{{appname}}' => $appName],
            );
        }

        $replace = ['{{appname}}' => $appName];
        foreach ($bodyVars as $key => $value) {
            $replace['{{'.$key.'}}'] = $value;
        }

        return [$subject, strtr($template, $replace)];
    }

    private function send(string $to, string $subject, string $text, string $failure): void
    {
        $appName = $this->config->get('APP_NAME', 'ArcPanel');
        $hostname = (string) gethostname();
        $from = $this->config->get('FROM_EMAIL') ?: 'noreply@'.$hostname;
        $fromName = $this->config->get('FROM_NAME') ?: $appName;
        if ($this->config->get('USE_SERVER_SMTP') === 'true' && filter_var($this->config->get('SERVER_SMTP_ADDR'), FILTER_VALIDATE_EMAIL)) {
            $from = $this->config->get('SERVER_SMTP_ADDR');
        }

        try {
            Transport::fromDsn($this->dsn())->send(
                (new Email)
                    ->from(new Address($from, $fromName))
                    ->to($to)
                    ->subject($subject)
                    ->html(nl2br($text)),
            );
        } catch (Throwable $e) {
            $this->log->warning($failure, ['error' => $e->getMessage()]);
        }
    }

    private function template(string $name, string $language): ?string
    {
        $dir = rtrim($this->installDir, '/').'/data/templates/email/';
        $candidates = $language !== '' ? [$dir.basename($language).'/'.$name.'.html'] : [];
        foreach ([...$candidates, $dir.$name.'.html'] as $file) {
            if (is_readable($file)) {
                return (string) file_get_contents($file);
            }
        }

        return null;
    }

    private function dsn(): string
    {
        if ($this->config->get('USE_SERVER_SMTP') !== 'true') {
            return 'sendmail://default';
        }

        $scheme = $this->config->get('SERVER_SMTP_SECURITY') === 'ssl' ? 'smtps' : 'smtp';

        return sprintf(
            '%s://%s:%s@%s:%s',
            $scheme,
            rawurlencode($this->config->get('SERVER_SMTP_USER')),
            rawurlencode($this->config->get('SERVER_SMTP_PASSWD')),
            $this->config->get('SERVER_SMTP_HOST'),
            $this->config->get('SERVER_SMTP_PORT', '587') ?: '587',
        );
    }
}

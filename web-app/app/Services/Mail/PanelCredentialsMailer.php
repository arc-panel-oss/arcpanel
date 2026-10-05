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
final class PanelCredentialsMailer implements AccountMailer, CredentialsMailer
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

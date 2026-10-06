#!/bin/bash
# Apply the rename rules of mapping.tsv to the tracked files of the
# current repository (full rename, ADR-0007, spec 2026-10-06-005 §8).
#
# Usage: scripts/rename/apply.sh [--dry-run | --check] [--review FILE] [PATH...]
#
#   --dry-run      list every rename and edit, change nothing
#   --check        report old names that keep.txt does not allow and doubled
#                  names, change nothing (test/brand-check.sh)
#   --review FILE  write every changed line that holds sed, grep, awk,
#                  perl or a regular expression to FILE (spec §13)
#   PATH           limit the run to these paths (default: whole tree)
#
# Order of work: guards, file renames (git mv), then text rewrites with
# the rules in file order. Binary files, submodules, symlinks and the
# matches of keep.txt are left alone. Nothing is written when a guard
# still matches, a rename target exists or a rewrite produces a doubled
# name such as "arcpanelcp".
#
# Exit codes: 0 done, 1 refused (guard, collision, doubled name) or, with
# --check, old names found, 2 usage.

set -euo pipefail

rules_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
dry_run=0
review=""
paths=()

while [ $# -gt 0 ]; do
	case "$1" in
		--dry-run)
			dry_run=1
			;;
		--check)
			dry_run=check
			;;
		--review)
			[ $# -ge 2 ] || {
				echo "apply.sh: --review needs a file" >&2
				exit 2
			}
			review="$2"
			shift
			;;
		-h | --help)
			sed -n '2,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
			exit 0
			;;
		--)
			shift
			paths+=("$@")
			break
			;;
		-*)
			echo "apply.sh: unknown option $1" >&2
			exit 2
			;;
		*)
			paths+=("$1")
			;;
	esac
	shift
done

top="$(git rev-parse --show-toplevel)"
if [ -n "$review" ]; then
	case "$review" in
		/* | [A-Za-z]:*) ;;
		*) review="$PWD/$review" ;;
	esac
fi
cd "$top"

exec perl -we '
use strict;
use warnings;

my ($rules_dir, $dry, $review, @paths) = @ARGV;

sub glob_re {
	my ($g) = @_;
	my $r = "";
	while ($g =~ /\G(\*\*\/?|\*|\?|[^*?]+)/gc) {
		my $t = $1;
		if    ($t eq "**/") { $r .= "(?:.*/)?" }
		elsif ($t eq "**")  { $r .= ".*" }
		elsif ($t eq "*")   { $r .= "[^/]*" }
		elsif ($t eq "?")   { $r .= "[^/]" }
		else                { $r .= quotemeta $t }
	}
	return qr/^$r$/s;
}

sub scope_re {
	my ($scope) = @_;
	return undef if $scope eq "*";
	my $alt = join "|", map { glob_re($_) } split /,/, $scope;
	return qr/$alt/;
}

sub in_scope {
	my ($re, $path) = @_;
	return !defined($re) || $path =~ $re;
}

sub read_table {
	my ($file, $min, $max) = @_;
	open my $fh, "<", $file or die "apply.sh: cannot read $file: $!\n";
	my @rows;
	while (my $line = <$fh>) {
		$line =~ s/\r?\n\z//;
		next if $line =~ /^\s*(#|$)/;
		my @f = split /\t/, $line;
		die "apply.sh: $file:$.: expected $min to $max tab separated fields\n"
			if @f < $min || @f > $max;
		push @rows, [ $., @f ];
	}
	return @rows;
}

# --- Rules
my (@guards, @path_rules, @text_rules);
for my $row (read_table("$rules_dir/mapping.tsv", 4, 4)) {
	my ($ln, $kind, $old, $new, $scope) = @$row;
	my $rule = { line => $ln, kind => $kind, old => $old, new => $new,
		scope => $scope, re => scope_re($scope), files => 0, lines => 0 };
	if    ($kind eq "guard") { push @guards, $rule }
	elsif ($kind eq "path")  { push @path_rules, $rule }
	elsif ($kind eq "text")  { push @text_rules, $rule }
	else { die "apply.sh: mapping.tsv:$ln: unknown kind $kind\n" }
}

my (@keep_files, @keep_masks);
for my $row (read_table("$rules_dir/keep.txt", 2, 3)) {
	my ($ln, $kind, $value, $scope) = @$row;
	if ($kind eq "file") {
		push @keep_files, glob_re($value);
	} elsif ($kind eq "text" || $kind eq "regex") {
		die "apply.sh: keep.txt:$ln: $kind needs a scope\n" unless defined $scope;
		my $re = $kind eq "text" ? qr/\Q$value\E/ : qr/$value/;
		push @keep_masks, { re => $re, scope => scope_re($scope) };
	} else {
		die "apply.sh: keep.txt:$ln: unknown kind $kind\n";
	}
}

sub kept_file {
	my ($path) = @_;
	for my $re (@keep_files) { return 1 if $path =~ $re }
	return 0;
}

# --- Tracked files (mode 160000 is a submodule, 120000 a symlink)
my @files;
{
	open my $ls, "-|", "git", "ls-files", "-s", "-z", "--", @paths
		or die "apply.sh: git ls-files failed\n";
	local $/ = "\0";
	while (my $entry = <$ls>) {
		chomp $entry;
		my ($mode, $path) = $entry =~ /^(\d+) \S+ \d+\t(.*)\z/s or next;
		next if $mode eq "160000";
		push @files, { path => $path, link => $mode eq "120000" };
	}
	close $ls or die "apply.sh: git ls-files failed\n";
}

sub slurp {
	my ($path) = @_;
	open my $fh, "<:raw", $path or die "apply.sh: cannot read $path: $!\n";
	local $/;
	my $c = <$fh>;
	return defined $c ? $c : "";
}

my $refused = 0;
my $doubled = qr/arcpanel_?cp|arcpanel_?arcpanel|arcpanel-arcpanel|ArcPanelCP|ARCPANELCP|ARCPANEL_?ARCPANEL/;

# --- Check: old names outside keep.txt, and doubled names
if ($dry eq "check") {
	my $found = 0;
	for my $f (@files) {
		my $p = $f->{path};
		next if $f->{link} || kept_file($p) || ! -e $p || -B $p;
		my $c = slurp($p);
		for my $k (@keep_masks) {
			next unless in_scope($k->{scope}, $p);
			$c =~ s/$k->{re}/\x00/g;
		}
		my $n = 0;
		for my $line (split /\n/, $c) {
			$n++;
			next unless $line =~ /hestia|\bhst[_-]/i || $line =~ $doubled;
			(my $shown = $line) =~ s/\r\z//;
			$shown =~ s/\x00/…/g;
			print "$p:$n: $shown\n";
			$found++;
		}
	}
	exit($found ? 1 : 0);
}
# --- Guards
for my $g (@guards) {
	for my $f (@files) {
		my $p = $f->{path};
		next if $f->{link} || kept_file($p) || !in_scope($g->{re}, $p) || -B $p;
		my $n = 0;
		for my $line (split /\n/, slurp($p)) {
			$n++;
			next unless index($line, $g->{old}) >= 0;
			print "guard  $p:$n: $g->{old} (mapping.tsv:$g->{line})\n";
			$g->{files}++;
			$refused = 1;
		}
	}
}

# --- File renames
my %tracked = map { $_->{path} => 1 } @files;
my (%target_of, %source_of);
for my $f (@files) {
	my $old = $f->{path};
	next if kept_file($old);
	my $new = $old;
	for my $r (@path_rules) {
		next unless in_scope($r->{re}, $new) && index($new, $r->{old}) >= 0;
		$new =~ s/\Q$r->{old}\E/$r->{new}/g;
		$r->{files}++;
	}
	next if $new eq $old;
	if (exists $source_of{$new}) {
		print "clash  $old and $source_of{$new} both become $new\n";
		$refused = 1;
	} elsif ($tracked{$new} || -e $new) {
		print "clash  $old -> $new: target exists\n";
		$refused = 1;
	}
	$source_of{$new} = $old;
	$target_of{$old} = $new;
	print "rename $old -> $new\n";
}

# --- Text rewrites, computed in memory first
my $review_re = qr/\b(?:sed|grep|egrep|awk|perl)\b|preg_\w+|=~|\bs([|#\/,]).+\1/;
my (%content_of, @review_lines);
for my $f (@files) {
	my $old = $f->{path};
	my $path = exists $target_of{$old} ? $target_of{$old} : $old;
	next if $f->{link} || kept_file($old) || kept_file($path) || -B $old;
	my $orig = slurp($old);
	my $c = $orig;

	my @saved;
	for my $k (@keep_masks) {
		next unless in_scope($k->{scope}, $path);
		$c =~ s/($k->{re})/push @saved, $1; "\x00K" . $#saved . "\x00"/ge;
	}
	my $before_rules = $c;
	for my $r (@text_rules) {
		next unless in_scope($r->{re}, $path);
		my $n = () = $c =~ /^[^\n]*?\Q$r->{old}\E/mg;
		next unless $n;
		$c =~ s/\Q$r->{old}\E/$r->{new}/g;
		$r->{files}++;
		$r->{lines} += $n;
	}
	next if $c eq $before_rules;

	my @was = split /\n/, $before_rules, -1;
	my @now = split /\n/, $c, -1;
	my $changed = 0;
	for my $i (0 .. $#now) {
		next if $now[$i] eq $was[$i];
		$changed++;
		(my $shown = $now[$i]) =~ s/\x00K(\d+)\x00/$saved[$1]/g;
		$shown =~ s/\r\z//;
		if ($now[$i] =~ $doubled) {
			print "double $path:" . ($i + 1) . ": $shown\n";
			$refused = 1;
		}
		push @review_lines, "$path:" . ($i + 1) . ": $shown"
			if $now[$i] =~ $review_re;
	}
	$c =~ s/\x00K(\d+)\x00/$saved[$1]/g;
	$content_of{$path} = $c;
	print "edit   $path ($changed lines)\n";
}

# --- Summary
print "\nRules (mapping.tsv line, kind, old -> new, scope: files, lines)\n";
for my $r (@guards, @path_rules, @text_rules) {
	my $what = $r->{kind} eq "guard" ? "$r->{old}" : "$r->{old} -> $r->{new}";
	my $count = $r->{kind} eq "text" ? "$r->{files} files, $r->{lines} lines"
		: $r->{kind} eq "path" ? "$r->{files} paths" : "$r->{files} hits";
	printf "  %3d %-5s %s [%s]: %s\n", $r->{line}, $r->{kind}, $what, $r->{scope}, $count;
}
printf "\n%d renames, %d edited files, %d lines to review\n",
	scalar(keys %target_of), scalar(keys %content_of), scalar(@review_lines);

if ($review ne "") {
	open my $out, ">", $review or die "apply.sh: cannot write $review: $!\n";
	print $out "$_\n" for @review_lines;
	close $out;
}

if ($refused) {
	print "\nRefused: fix the guard, clash or double lines above first.\n";
	exit 1;
}
exit 0 if $dry;

# --- Write
for my $old (sort keys %target_of) {
	my $new = $target_of{$old};
	(my $dir = $new) =~ s{/[^/]*\z}{};
	system("mkdir", "-p", "--", $dir) == 0 or die "apply.sh: mkdir $dir failed\n"
		if $dir ne $new;
	system("git", "mv", "--", $old, $new) == 0
		or die "apply.sh: git mv $old $new failed\n";
}
for my $path (sort keys %content_of) {
	open my $fh, ">:raw", $path or die "apply.sh: cannot write $path: $!\n";
	print $fh $content_of{$path};
	close $fh or die "apply.sh: cannot write $path: $!\n";
}
print "Done.\n";
' "$rules_dir" "$dry_run" "$review" "${paths[@]+"${paths[@]}"}"

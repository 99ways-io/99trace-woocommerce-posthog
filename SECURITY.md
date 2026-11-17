# Security policy

## Supported versions

Security fixes go into the latest release of 99Trace. Please update before reporting.

## Reporting a vulnerability

Report privately through GitHub: open the repository's **Security** tab and choose **Report a vulnerability**. Please do not open a public issue, pull request or forum topic for a security problem.

Include the affected version, the steps or proof of concept, and the impact you see. We acknowledge reports within three working days, keep you informed while we fix the issue, and credit you in the release notes unless you prefer otherwise. Please give us a reasonable time to release a fix before you disclose the issue publicly.

99Trace handles shoppers' analytics identifiers and, when a shop enables it, customer details sent to the shop's own PostHog project. Reports about data reaching PostHog that the documentation says never does (order keys, postal addresses, e-mail addresses inside events or URLs) are treated as security issues.

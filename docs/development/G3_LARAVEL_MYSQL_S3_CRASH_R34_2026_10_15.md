# G3-R34 — Disposable Laravel / MySQL / S3 Crash Trial

Status: DRAFT / G3 OPEN / no production approval.

R33 tested storage process restart but did not exercise the Laravel DB transaction boundary. R34 must use disposable MySQL and localhost S3-compatible storage with actual application services. Planned cases: crash after durable journal prepare, after object write but before owner transaction commit, and after owner commit but before journal settlement. Run fresh Laravel process inspection after crash and storage restart. Assert owner references, journal status, object bytes and generation history remain distinguishable, with no automatic delete or retry.

This document is a scope definition, not proof that any R34 CI test has run. Real AWS credentials, production Render/Aiven, new application migrations, IAM changes and destructive operations remain prohibited. G3 OPEN.

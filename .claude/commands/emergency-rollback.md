---
description: Emergency Rollback Procedures
---
# Emergency Rollback Procedures

This workflow triggers when a deployment to staging or production fails fatally, or if severe bugs are caught post-deploy.

## Staging Rollback
1. An issue is detected on `staging5.blog.njohansson.eu` (by a Human or Reviewer).
2. The **Coder** or Human runs the rollback script:
   ```bash
   ./scripts/rollback-staging.sh <timestamp>
   ```
   (where `<timestamp>` is fetched from `/state/current-task.yml` or standard output.)
3. Validate recovery by clearing cached data and testing the staging site again.

## Production Rollback
Since we rely on Siteground Site Tools for production deployments:
1. **STOP all agent activity.**
2. A **Human** must manually revert the push utilizing Siteground Site Tools "Backups" tab or doing a reverse custom deploy.
3. Inform the Orchestrator that the task state is `failed`.

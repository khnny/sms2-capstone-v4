---
name: crad-e2e-qa
description: Conduct safe, evidence-based end-to-end testing, regression testing, and defect analysis for the CRAD research management system, including new features, role-based workflows, database integrity, OCR, integrations, and HostForge production behavior.
---

# CRAD End-to-End QA Skill

## Purpose

Use this skill whenever asked to test, audit, validate, debug, or verify the CRAD system, its complete research lifecycle, recently added features, or fixes to previously identified defects.

The objective is to produce reproducible, evidence-based test results while protecting production data and preserving existing business rules.

## Operating rules

1. Treat the actual repository implementation as the source of truth for current behavior.
2. Use documentation and requirements to determine intended behavior.
3. Do not assume an undocumented feature exists.
4. Do not load the entire repository into context.
5. Search first and inspect only relevant files.
6. Never claim a test passed unless it was executed and its expected result was verified.
7. Never use destructive tests on live production data.
8. Do not change code during baseline testing unless explicitly authorized.
9. After authorized fixes, rerun the failed tests and relevant regressions.
10. Keep a clear distinction between confirmed facts, inferred causes, and unresolved questions.

## Step 1 — Establish the environment

Determine:

- Repository and current branch.
- Current commit and recent changes, if available.
- Application entry points and module layout.
- Existing test framework and test commands.
- Database connection strategy and schema.
- Available staging or isolated test environment.
- Relevant HostForge deployment configuration and logs.

Do not print secrets or retrieve unnecessary sensitive data.

If the deployed commit cannot be established, document that limitation.

### Environment safety gate

Before state-changing tests, verify that the target uses isolated test data and test credentials.

If isolation cannot be established:

- Restrict testing to static analysis and read-only checks.
- Do not submit, approve, reject, delete, or modify actual research records.
- Do not upload test receipts into live payment workflows.
- Do not send real notifications or trigger external side effects.
- Report which tests are blocked.

## Step 2 — Discover modules and new features

Use targeted searches to identify:

- Pages and routes.
- Controllers and handlers.
- Shared helpers and services.
- Database tables and relationships.
- User roles and permissions.
- Upload and document-processing workflows.
- AI and external API integrations.
- Notifications, reports, and exports.
- Existing automated tests.

Inspect recent Git changes and project documentation to identify newly added features.

Maintain a feature inventory with these fields:

| Field | Description |
|---|---|
| Feature | Name of the module or feature |
| Entry point | Page, route, or API |
| Dependencies | Helpers, tables, services |
| Roles | Authorized actors |
| Expected behavior | Requirement or verified business rule |
| Test coverage | Existing and missing tests |
| Status | Verified, partial, missing, or unclear |

Only investigate features relevant to the requested testing scope.

## Step 3 — Build the workflow map

Trace actual state transitions from research submission through review, approval, revisions, defense, final manuscript, publication, and repository management, wherever these stages exist.

For each workflow, record:

- Starting state.
- Required actor and permissions.
- Required inputs.
- Validation rules.
- Allowed transitions.
- Database effects.
- Notifications and integration effects.
- Failure and recovery paths.
- Expected final state.

Confirm every transition from implementation or documented requirements. Do not invent business rules to make tests pass.

## Step 4 — Create test cases

Assign a unique test ID to every scenario.

Use this format:

| Field | Required content |
|---|---|
| Test ID | Unique identifier, e.g. CRAD-WF-001 |
| Module | Feature being tested |
| Preconditions | Required records and roles |
| Steps | Reproducible actions |
| Expected result | Requirement-based outcome |
| Actual result | Observed outcome |
| Evidence | Log, assertion, response, or screenshot |
| Status | PASS, FAIL, BLOCKED, NOT TESTED, or NOT APPLICABLE |

Include applicable positive, negative, boundary, permission, duplicate-request, persistence, failure-recovery, and regression cases.

## Step 5 — Execute tests in layers

### Layer A: Static checks

Check relevant files for:

- PHP syntax errors.
- Missing functions.
- Broken include/require paths.
- Undefined variables.
- SQL syntax risks.
- Missing dependencies.
- Inconsistent route or field references.

Static analysis alone does not prove runtime correctness.

### Layer B: Unit tests

Test isolated functions such as:

- Validation.
- Status transition rules.
- OR Number parsing.
- Permission checks.
- Formatting and data transformation.

Use deterministic inputs and expected outputs.

### Layer C: Integration tests

Verify interactions among:

- PHP handlers and database operations.
- Shared helper functions.
- Approval and revision workflows.
- Upload storage and document processing.
- Notification services.
- AI/API integrations.

Check both successful operations and failure handling.

### Layer D: End-to-end tests

Use isolated accounts representing the actual roles in the application.

Verify complete workflows across multiple pages and modules. Confirm database persistence and final states rather than relying on visual page success.

### Layer E: Regression tests

Rerun relevant tests for all previously fixed defects and affected dependencies.

## Step 6 — Test feature-specific behavior

Apply these checks when the feature exists.

### Research workflow

- Submission and validation.
- Approval, rejection, and revision.
- Correct queues and status transitions.
- Scheduling and conflict checks.
- Required document completion.
- Final manuscript review and approval.
- Publication record creation.
- Repository publication and access.

### Receipt OCR

- Supported file formats.
- Upload validation and storage.
- OCR execution and response parsing.
- OR Number accuracy.
- Leading-zero preservation.
- Database persistence.
- Manual correction.
- Low-confidence handling.
- Duplicate and failure behavior.

Never generate an OR Number when extraction is uncertain.

### AI integrations

- Valid and malformed responses.
- Timeouts and unavailable services.
- Invalid or unexpected output.
- Rate limits and error handling.
- Output validation.
- Authorization and sensitive-data protection.
- External API cost and side-effect controls.

### Reports and exports

- Correct filters and record counts.
- Accurate field mapping.
- Consistency with authorized source records.
- Correct handling of empty results.
- Access control and sensitive data exposure.

### Authorization and security

- Direct URL and API access.
- Server-side role checks.
- Record ownership.
- Upload and download permissions.
- Input validation and output escaping.
- Session and CSRF controls where applicable.

Use non-destructive checks unless an isolated test environment is confirmed.

## Step 7 — Investigate HostForge failures

Use available evidence from the deployed environment.

- Build logs: dependency installation and image creation.
- Deployment logs: release stages and health checks.
- Runtime logs: application errors and exceptions.
- Runtime status: restarts, health, and resource usage.
- Dockerfile: installed packages, PHP extensions, paths, and startup behavior.
- Environment configuration: required variable presence without revealing values.

Correlate errors with the relevant request, test, and deployed version whenever possible.

Do not assume localhost results prove HostForge functionality.

## Step 8 — Report and fix defects

For every failure, document:

- Test ID.
- Reproduction steps.
- Expected and actual results.
- Evidence.
- Severity.
- Confirmed or suspected root cause.
- Relevant files and dependencies.
- Minimal recommended fix.

Use these severity definitions:

- Critical: major data integrity failure, unauthorized access, or severe workflow compromise.
- High: essential submission, approval, publication, or integration failure.
- Medium: partial functionality or incorrect validation.
- Low: minor usability or non-blocking defect.

Do not make unrelated changes.

If code modification is authorized, implement the smallest safe fix and rerun:

1. The original failed test.
2. Related integration tests.
3. Relevant regression tests.

## Step 9 — Test result rules

Use exactly these result categories:

- PASS: executed and met the defined expected result.
- FAIL: executed and did not meet the expected result.
- BLOCKED: could not execute due to a documented blocker.
- NOT TESTED: not executed.
- NOT APPLICABLE: the feature does not exist or the scenario is irrelevant, with justification.

A page loading successfully does not prove that its workflow, permissions, or database operations work.

A code change does not prove that the defect is fixed.

## Step 10 — Final deliverables

Produce:

1. Module and feature inventory.
2. Research lifecycle and dependency map.
3. Test case matrix.
4. Execution summary.
5. Defect and severity report.
6. Newly added feature assessment.
7. Database and authorization findings.
8. Integration and OCR results where applicable.
9. Files changed and explanation of fixes.
10. Regression results.
11. Blocked and unverified scenarios.
12. Release-readiness recommendation.

Conclude with a clear decision:

- READY FOR FURTHER STAGING VALIDATION
- NOT READY — CRITICAL OR HIGH DEFECTS REMAIN
- INCONCLUSIVE — REQUIRED TESTS ARE BLOCKED

Do not declare the application production-ready solely because all executed tests passed. State the scope and limitations of the assessment.

## Token efficiency

- Search before opening files.
- Read relevant function ranges instead of entire large files.
- Reuse existing tests and fixtures.
- Avoid duplicate investigations.
- Summarize findings after each phase.
- Preserve a compact list of unresolved issues and test IDs.
- Do not rerun unrelated tests unless a change affects their dependencies.

Always prioritize correctness, traceability, production safety, and reproducible evidence.
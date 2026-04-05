# /project:security-review
# Run this before marking any task complete.

You are performing a security review of the code just written for LoreBuilder.
Check each item in this list and report PASS, FAIL, or N/A with a one-line explanation.

## Authentication & Authorisation
- [ ] Does every new endpoint call Auth::requireSession() before any logic?
- [ ] Does every world-scoped endpoint call Guard::requireWorldAccess()?
- [ ] Is the role checked correct for the operation (viewer/author/admin/owner)?
- [ ] Are world_id values taken from the validated route parameter, NOT from the request body?

## Input & SQL
- [ ] Is all user input passed through Validator::parse() with an explicit allowlist?
- [ ] Are all DB queries using PDO prepared statements via DB::query()?
- [ ] Is there any dynamic SQL construction (string concatenation into queries)? If yes: FAIL.
- [ ] Are file uploads (if any) rejected for executable MIME types and stored outside web root?

## Output & Encoding
- [ ] Is all user-controlled data encoded with htmlspecialchars() before any HTML output?
- [ ] If AI response text is rendered in Vue, does it go through MarkdownRenderer.vue (Marked + DOMPurify)?
- [ ] Is v-html used anywhere with unvalidated content? If yes: FAIL.

## CSRF
- [ ] For POST/PATCH/PUT/DELETE endpoints: is CSRF verified by the Router automatically?
- [ ] If a new method is added to Router.php, is it included in the CSRF-required list?

## API Keys & Secrets
- [ ] If an API key is touched: is it decrypted immediately before use and zeroed (sodium_memzero) after?
- [ ] Is any API key included in any response JSON? If yes: FAIL.
- [ ] Is any API key written to any log? If yes: FAIL.
- [ ] Is config.php in .gitignore? (Check .gitignore if config touched.)

## Rate Limiting
- [ ] If this is an AI endpoint: are both per-user and per-world rate limits applied?
- [ ] If this is a login/register endpoint: is failed-attempt tracking applied?

## Audit Log
- [ ] Is a meaningful audit_log entry written for every entity mutation?
- [ ] Does the diff_json capture before/after state (not raw passwords or API keys)?

## Report
List each FAIL item with:
  - File and line number
  - Nature of the issue
  - Recommended fix

Then append any FAILs to SECURITY_FINDINGS.md in the required format.
If all items pass, confirm: "Security review passed — no issues found."

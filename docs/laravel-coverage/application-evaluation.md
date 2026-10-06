# Laravel application evaluation

Status: candidate evaluation complete, reviewed 2026-10-06. All five application comparisons and post-run source provenance are recorded. This is evidence for the intermediate Laravel milestone's L0/L1/L3 work, not a completed milestone, release or runtime certification. [The machine-readable baseline and candidate record](application-evaluation.json) retains source commits, immutable input fingerprints, package dispositions, report hashes, scenario outcomes and normalized finding changes.

## Corpus and requests

The three primary applications are public, independently maintained projects. Two additional BookStack snapshots exercise a multi-hop path and a newer package set. No application source or vendor tree is committed here.

| Case | Pinned snapshot | Locked Laravel | Requested upgrade |
| --- | --- | --- | --- |
| Crater, primary | [05d5ce26](https://github.com/crater-invoice-inc/crater/tree/05d5ce26fdd8d9466009163444e944259bc0cc2a) | 8.83.16 | PHP 7.4->8.1; Laravel 9 |
| BookStack, primary | [v24.10.3 / 07e45a20](https://github.com/BookStackApp/BookStack/tree/07e45a20e55fc955e9ef252d0653f23193a71a77) | 10.48.25 | PHP 8.1->8.2; Laravel 11 |
| Lychee, primary | [v6.10.0 / bf5495c7](https://github.com/LycheeOrg/Lychee/tree/bf5495c706a9971ebfcd03a88c36e336fc0261d5) | 12.36.1 | PHP 8.3; Laravel 13 |
| BookStack9, supplemental | [v23.12.2 / 9441e32c](https://github.com/BookStackApp/BookStack/tree/9441e32c69acd80eeaef47b87dc6b0c1f9584215) | 9.52.16 | PHP 8.1->8.2; Laravel 11 |
| BookStack12, supplemental | [f585a7d9](https://github.com/BookStackApp/BookStack/tree/f585a7d9100a99cba8479c1ef5fd56e05562daea) | 12.69.3 | PHP 8.2->8.3; Laravel 13 |

The historical harness identifier `bookstack10` means the supplemental Laravel 9 snapshot, not Laravel 10. Both primary and supplemental BookStack snapshots retain HTTP and console kernels. Their presence does not require adoption of the optional Laravel 11 skeleton.

## What the saved comparisons show

Baseline tools were the published CLI, core and Laravel packages at exactly 0.3.3, installed in a separate tools directory. The evaluated candidate was an unpublished working tree whose metadata still said 0.3.3; that shared version string was not proof of identical implementation. The JSON records each published source reference separately. Release preparation subsequently advanced current tool identity to 0.3.4, then 0.3.5 after a distribution export mode defect was found, without rewriting these recorded runs. The exporter repair does not change the evaluated analyzer behavior.

The tools and monorepo candidate have separately locked dependency graphs; the JSON records baseline dependency versions and the candidate's relevant analyzer libraries. This is not a fully controlled dependency-version experiment. Sanitized red/green regression cases independently establish the named corrections, and networked timings are not causal performance comparisons. Reinstalling exact tool versions later can resolve different transitive versions; compare the recorded lock hash/inventory rather than assuming a fresh install reproduces every byte.

| Case | Direct baseline / candidate | Staged baseline / candidate | Findings baseline / candidate |
| --- | --- | --- | --- |
| Crater | blocked / blocked | blocked / blocked | 8 / 7 |
| BookStack (Laravel 10 -> 11) | blocked / blocked | blocked / blocked | 4 / 3 |
| Supplemental BookStack (9 -> 11) | blocked / blocked | blocked / blocked | 7 / 5 |
| Supplemental BookStack (12 -> 13) | blocked / blocked | feasible_with_changes / feasible_with_changes | 4 / 4, different findings |
| Lychee (12 -> 13), public-HTTPS retry pair | blocked / blocked | blocked / blocked | 3 / 4 |

All saved runs report supported framework guidance. That result is independent of direct resolution and carried staged candidates. For example, supplemental BookStack12's staged candidate is feasible with changes even though its direct request is blocked by locked Tinker 2 contracts.

The supplemental Laravel 9 run removes two false-positive 9 -> 10 recommendations: supported PHPUnit 9.6.16 and Collision 6.4 need not jump major for Laravel 10. They still need compatible updates for the later Laravel 11 hop. Supplemental BookStack12 drops the false-positive PHPUnit 12 requirement for supported PHPUnit 11.5.56 and adds a detected inherited CSRF reference as a medium advisory. Lychee's PHPUnit 11.5.42 still needs a patch update, but 11.5.50+ is supported without forcing a major-12 migration; the candidate also detects its inherited deprecated CSRF alias. None of these changes proves application tests pass.

Primary BookStack's `final-candidate` report removes the PHPUnit 10.5.38 false positive. The earlier candidate containing it remains identified by its own hash. Crater's `accepted-candidate` report removes low-severity UI 4.x advice for supported UI 3.4.6, with the same immutable source inputs. The table selects that accepted Crater label, `final-candidate` for BookStack cases and matched `baseline-public-https` / `final-public-https` for Lychee. Every earlier failed or preliminary report remains in the JSON.

Composer's live security-advisory policy rejects some historical Laravel and DOMPDF versions. Locked diagnostic package conflicts remain useful evidence, but they are not the only reason for failed networked solves. An unclassified PHP failure is recorded at low confidence and is not evidence that the declared target PHP is inherently unsupported. Abandoned-package findings are maintenance advice, not independently sufficient feasibility blockers.

## Maintainer work still required

Crater needs compatible DOMPDF/Illuminate consumers, Ignition replacement, middleware review and Flysystem 3 adapter work. Test invoices/PDF generation, mail, storage, media processing, backup restore, authorization, Sanctum sessions and queued payloads. Retain supported UI 3.4.6; validate actual generated authentication/bootstrap usage during migration.

Primary BookStack needs Collision compatible with Laravel 11, not a mandatory PHPUnit 11 jump. Retain its skeleton unless migration is a deliberate choice. Remove DBAL only after ruling out direct use; test schema operations, the mailer fork, PDF/image processing, SAML and OAuth providers. The supplemental Laravel 9 snapshot additionally needs its DOMPDF/Snappy bridges and SocialiteProviders manager updated for the final target. The supplemental Laravel 12 snapshot needs compatible Tinker and a CSRF behavior review.

Lychee's public-HTTPS retry now produces conclusive solver failures: locked Spatie image optimizer excludes Laravel 13 and Collision 8.8.2 declares a conflict with 13. Other rooted ecosystem contracts end at 12. Review those packages and its forks, update PHPUnit within a supported line, and test CSRF exemptions, sessions, WebAuthn/OAuth, payment gateways, image/EXIF/FFmpeg processing, storage, migrations, uploads and background jobs. Extension presence assumptions do not verify required binaries or deployment versions.

The JSON inventories every rooted first-party package and rooted guide/test package in this bounded corpus, plus all root requirements for context. Other ecosystem packages are not claimed to have dedicated adapter rules. [Legacy](legacy-guide-audit.json) and [modern](modern-guide-audit.json) ledgers explain the guide-level detection and manual-review boundaries.

Confirmed corpus corrections map to sanitized, repository-owned tests: [legacy package alternatives](../../packages/laravel/tests/Unit/Rules/LaravelCompletionPackageRulesTest.php), [modern PHPUnit alternatives](../../packages/laravel/tests/Unit/Rules/LaravelModernPhpunitRulesTest.php), [inherited CSRF references](../../packages/laravel/tests/Unit/Rules/LaravelHighSignalSourceRuleTest.php), and [Laravel UI compatibility](../../packages/laravel/tests/Unit/Rules/LaravelUiCompatibilityRulesTest.php). Their positive and negative cases exercise the narrow rule boundaries without importing these applications or running their tests.

## Execution, immutability and reproduction

The harness uses PHP 8.3.33 and Composer 2.10.2 in Docker, compatible execution mode, 45-second scenario and 15-second diagnostic timeouts. Scripts, plugins, installation, audit execution, interaction and progress are disabled. Network and repository/global configuration are inherited unless a retry explicitly records a command-scoped policy change. Disabling audit execution does not disable Composer's advisory-based dependency blocking.

Requests explicitly assume these extensions present, without exact versions: bcmath, curl, exif, fileinfo, gd, imagick, mbstring, openssl, pdo, xml and zip. This is a partial profile; unlisted extensions retain analyzer-runtime provenance. The source PHP in each request is supplied, not inferred from the analyzer host.

Before and after each completed analysis, the harness hashes every regular input file except `.git`, sorts the relative-path/SHA256 map and hashes its JSON encoding. The saved file count and digest match strictly. Committed manifest/lock blob hashes are recorded separately. These checks establish unchanged source inputs during analysis; they do not prove runtime behavior. No application tests, migrations or application boot were run.

To reproduce, obtain the exact public commits above in disposable worktrees, retain their committed manifests/locks, and install the published tools in a separate disposable directory:

```powershell
# In the evaluation tools directory; PHP/Composer run only through Docker.
docker compose run --rm -T -v "${evaluationRoot}:/evaluation" php composer --working-dir=/evaluation/tools require php-upgrade-preflight/cli:0.3.3 php-upgrade-preflight/laravel:0.3.3 --no-scripts --no-plugins --no-interaction
# Use a fresh report label; the committed helper refuses existing outputs.
docker compose run --rm -T -v "${evaluationRoot}:/evaluation" php php -d memory_limit=1024M /app/docs/laravel-coverage/evaluate.php /evaluation/tools/vendor/autoload.php crater replay-baseline
docker compose run --rm -T -v "${evaluationRoot}:/evaluation" php php -d memory_limit=1024M /app/docs/laravel-coverage/evaluate.php /app/vendor/autoload.php crater replay-candidate
```

Set `$evaluationRoot` to a task-owned operating-system temporary directory containing `apps/`, `tools/` and `reports/`. Repeat the [committed helper](evaluate.php) command with `bookstack`, `bookstack10`, `bookstack12` and `lychee`; its per-case requests and common execution settings are specified in the JSON. Do not silently substitute source commits or regenerate application locks. This opt-in helper is not a shipped product command. It rejects unknown cases, unsafe labels and existing output labels before loading tools.

Recorded runs used the original external `evaluate.php`, SHA256 `acf1166ff7cb6189366ab2d14d5907f4b0a03946f6d79bce02b8f520df3dbc42`. The committed helper has a separate hash in the JSON: its request/digest/report logic is retained, but argument, directory and output guards were added. No full live application run with this promoted helper is claimed, and its hash is not substituted retroactively for the executing original.

After the accepted Crater run and restoration of selective-mutation edits, the stable candidate source observation contains 188 runtime/Composer input files with fingerprint `18cc3f5bd36ffcf19d94bba33fb48cc45c75258be5140974bee359f5f94d2c31`. The JSON records the exact file hashes, scope and algorithm. This is honest post-run provenance, not a claim that source fingerprints were captured around each earlier run. It excludes vendor, tests, documentation and the external harness.

Lychee's original and `-https` retries failed public repository cloning. A PHP 128MB attempt also exhausted memory before producing a valid report; only tool output exists for that attempt, so no fabricated report hash is provided. The 1024M rerun produced valid canonical evidence but still had unresolved access. The successful public-HTTPS retry restored Composer's default protocols and rewrote fallback SSH to public HTTPS only for the command:

```powershell
docker compose run --rm -T -v "${evaluationRoot}:/evaluation" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=url.https://github.com/.insteadOf -e GIT_CONFIG_VALUE_0=git@github.com: php php -d memory_limit=1024M /app/docs/laravel-coverage/evaluate.php /evaluation/tools/vendor/autoload.php lychee replay-baseline-public-https
docker compose run --rm -T -v "${evaluationRoot}:/evaluation" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=url.https://github.com/.insteadOf -e GIT_CONFIG_VALUE_0=git@github.com: php php -d memory_limit=1024M /app/docs/laravel-coverage/evaluate.php /app/vendor/autoload.php lychee replay-candidate-public-https
```

The retry neither edits the application repositories nor supplies private credentials. The canonical report retains inherited Composer-home/global-policy provenance; the command-level URL rewrite is recorded separately in the JSON. Target-platform-only scenarios succeed in both matched retry reports, while requested Laravel 13 scenarios produce package solver failures. Preliminary report bytes were not overwritten.

## Evidence limits and retention

Raw reports and summaries remain in the root agent's temporary evaluation directory, outside the repository; their hashes preserve identity, not public availability. Their identities, outcomes and normalized summaries have been consumed and independently reviewed. Automatic deletion is blocked by the execution environment, so these task-owned artifacts are retained rather than bypassing its safety policy. Normalized findings omit evidence IDs, paths and timings but preserve severity, summary text and hop attribution, so corrected ranges remain visible.

Live network/cache/security-policy measurements are non-deterministic and separate from offline regression gates. A [bounded retrospective feedback review](compatibility-baseline.json) covers 2026-08-21 through 2026-10-06: it is a review of existing public reports, not fresh solicitation or proof of compatibility. No post-release Laravel regression report was found in that bounded record. Neither document existence nor solver success closes the remaining milestone quality, CI, consumer-install, Wiki or release gates, or proves the applications safe to deploy.

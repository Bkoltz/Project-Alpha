# Private portal environment check

After deploying images that include this diagnostic, run this read-only command
**inside each web and cron container**:

```sh
php /var/www/bin/portal-readiness.php
```

Older images do not contain the command; a missing file is not evidence of
missing portal configuration. This diagnostic is packaged in both image targets.

## Presence-only check on an older image

From the deployment host's Compose directory, this command works without
updating the image. It reads four environment variables and prints only their
names and presence booleans; it does not load PHP application files or a database:

```sh
docker compose exec -T web php -r '$r=[];foreach(["EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL","PORTAL_INTEGRATION_HMAC_SECRETS_JSON","EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID","EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET"] as $k){$r[$k]=trim((string)getenv($k))!=="";}echo json_encode($r,JSON_PRETTY_PRINT),PHP_EOL;'
```

Repeat with `cron` instead of `web`. False does not mean stored credentials
are absent, and true does not prove correct or matching values. These are
container-process checks, not proof of the environment inherited by scheduled
jobs. Do not paste the host `.env`, Compose expansion, or secret values.

It accepts no arguments and emits only fixed readiness booleans and status codes. It never reads client records, connects to a database or receiver, loads the app bootstrap, sends email, or writes configuration. It checks the container's process environment, not a host `.env` file. Do not paste credentials or run `env`/`docker inspect` for this check. Sharing this command's JSON output does not disclose names, emails, routes, application IDs, key IDs, or secrets.

Compare `receiver_override_present` and `receiver_override_https_valid` between web and cron. A different result can explain why the web connection looks configured while background reconciliation cannot match its receiver. A missing override is not inherently wrong: deployments may derive the receiver from their saved external connection.

The direct signing fields check presence/shape only. An absent environment signing key or secret does **not** prove configuration is missing: Project Alpha may already have encrypted delivery credentials saved in its database. The JSON map check validates only that a JSON object exists; it does not select an application or verify any credential. A valid shape does not prove paired keys, authentication, or delivery works.

Database configuration, producer contract, runtime flags, receiver key matching, and overall activation are deliberately `unknown_not_checked`. This command is not an activation gate; continue through the existing administrator readiness workflow locally to verify them. No administrator credentials or client data need be shared with a support agent.

Exit codes: `0` report generated (not "portal ready"), `1` diagnostic failed with no exception details, `2` unsupported arguments (arguments are never echoed).

// Recreates the e2e database from the Sylius fixtures. Run it before a test run: the specs change data
// (they cancel orders, place orders, map payment methods) and claimed orders are not reused.
//
// Uses the PHP binary in PHP_BINARY (default: "php") and always the `e2e` environment. As a safety net it
// refuses to touch a database whose name does not contain "e2e".
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const php = process.env.PHP_BINARY ?? 'php';
const console = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../Application/bin/console');
const env = { ...process.env, APP_ENV: 'e2e' };

function run(args, options = {}) {
    process.stdout.write(`> bin/console ${args.join(' ')}\n`);

    return execFileSync(php, [console, ...args, '--env=e2e'], { env, encoding: 'utf8', stdio: options.capture ? 'pipe' : 'inherit' });
}

run(['doctrine:database:create', '--if-not-exists']);

const database = run(['dbal:run-sql', 'SELECT DATABASE() AS e2e_database'], { capture: true });
if (!/e2e/i.test(database.split('e2e_database').pop() ?? '')) {
    process.stderr.write(`Refusing to reset the database, its name does not contain "e2e":\n${database}\n`);
    process.exit(1);
}

run(['doctrine:schema:drop', '--force', '--full-database']);
run(['doctrine:schema:create']);
run(['sylius:fixtures:load', '--no-interaction']);

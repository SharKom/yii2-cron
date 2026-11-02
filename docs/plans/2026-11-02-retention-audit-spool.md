# Retention di intelligence_db_audit e commands_spool — Piano di implementazione

> **For Claude:** REQUIRED SUB-SKILL: Use sharkom-tools:executing-plans to implement this plan task-by-task.
> **APPLIED CONVENTIONS:** This plan applies the rules from skill `development-standards`. Load it in any execution session.

**Goal:** Pulizia notturna a blocchi di `intelligence_db_audit` (30 giorni) e `commands_spool` (14 giorni, mai le righe pendenti), con ricostruzione online della tabella sopra soglia, programmata dalle migrazioni (dispatcher su SoundOrgan, cron_job su NormaFlow) e prima esecuzione due minuti dopo il deploy.

**Architecture:** La parte comune (cancellazione a blocchi, ricostruzione online con soglie, report) sta in `sharkom/yii2-dev-helper`, namespace `sharkom\devhelper\maintenance`. Ogni pacchetto proprietario della tabella ha un servizio sottile che costruisce la condizione e un comando console ancora più sottile: `intelligence/maintenance/purge-audit` e `cron/maintenance/purge-spool`. Le migrazioni dei pacchetti (copiate nel core con prefisso `v2_2_5`) creano il system job con schedule notturna e una schedule `COUNT=1` materializzata subito; senza dispatcher creano un cron_job; senza nessuno dei due non fanno nulla.

**Tech Stack:** PHP 8 / Yii2 console, MariaDB 12.2 (InnoDB, `innodb_file_per_table=ON`), sharkom/job-dispatcher (RRULE, Materializer), sharkom/yii2-cron, Codeception (intelligence, yii2-cron), PHPUnit 11 (yii2-dev-helper), DB di test `soundorgan_test`.

---

## Scoperte che precisano il design (leggere prima di iniziare)

1. **La schedule `COUNT=1` va materializzata dalla migrazione.** Il Materializer crea occorrenze solo nella finestra `[max(adesso, dtstart), adesso + orizzonte]` (`Materializer::materializeDefinition`, riga ~351) e il materializzatore automatico gira **una volta per notte alle 2:00**. Una definition inserita a mano con dtstart = adesso + 2 minuti non viene mai materializzata: alle 2:00 il dtstart è già passato e l'unica occorrenza resta fuori finestra. La migrazione deve chiamare `Materializer::rematerializeForDefinition($id, 1)` (precedente: `m260802_100000_v2_0_38_cutover_pipeline_fatturazione::rimaterializza`). La schedule notturna invece viene materializzata alle 2:00 della prima notte: la 2:30/2:45 della prima notte è coperta.
2. **Niente transazione nella migrazione** (`up()`, non `safeUp()`): dopo l'inserimento il Materializer sveglia il dispatcher con `LPUSH job-dispatcher:wake`; dentro una transazione ancora aperta il dispatcher non vedrebbe l'evento e lo eseguirebbe solo al risveglio successivo. Ogni passo è idempotente.
3. **Catch-up.** `console.run` ha `catch_up_threshold_seconds = 3600`; il job lo imposta esplicitamente a 3600. Se il dispatcher è fermo durante il deploy e riparte entro un'ora, l'esecuzione unica parte comunque; oltre, l'evento diventa `missed` e la pulizia arriva la notte. Il ritardo si misura su `run_at` (Dispatcher.php ~286-290).
4. **Il Materializer usa `Yii::$app->db`** (ActiveRecord `ScheduleDefinition::findOne`). Con `php yii migrate --db=db_test` scriverebbe sul DB dell'applicazione: la migrazione materializza solo se `$this->db === Yii::$app->db`, altrimenti scrive un avviso.
5. **RRULE notturna con `BYSECOND=0`**: senza, php-rrule eredita i secondi del dtstart (le migrazioni esistenti usano `date('Y-m-d H:i:s')`).
6. **Formato degli args di `console.run`:** `['route' => ..., 'args' => ['--optimize=1']]`, `json_encode` singolo. `ConsoleCommandJob::normalizeShellArgs` converte `--max-seconds=1200` in `['maxSeconds' => '1200']`; anche Yii console accetta il kebab-case. Il comando gira **nello stesso processo del worker** (`Yii::$app->runAction`): la sessione SQL va ripristinata (`lock_wait_timeout`) in un `finally`.
7. **`logs_file` di commands_spool è condiviso tra le esecuzioni.** Sul DB dev: `/var/log/offers2mandates.log` è usato da 26.200 righe, `console/runtime/logs/command_spooler_.log` da 570, `command_cron_job_22.log` da 93 (lo spooler usa `@runtime/logs/command_{provenience}_{provenience_id}.log`, uguale per tutte le esecuzioni dello stesso job). Cancellare il file "della riga" cancellerebbe il log delle esecuzioni recenti. Regola: un file si rimuove solo se è dentro `@runtime/logs` (realpath, niente `..` né symlink verso fuori) **e** nessuna riga rimasta in tabella lo usa ancora. I file in `/var/log` non vengono mai toccati.
8. **`data_free` subito dopo una DELETE grande non misura lo spazio liberato:** InnoDB libera le pagine con la purge asincrona e `data_free` conta solo le extent libere; anche `data_length` è una statistica che si aggiorna in ritardo. Lo spazio liberato si stima quindi come `max(data_free, (data_length+index_length prima) × cancellate / (cancellate + righe rimaste))`. La ricostruzione salta comunque le righe delete-marked, quindi è efficace anche prima della purge.
9. **Spazio del datadir.** Il plugin `information_schema.DISKS` non è installato e l'utente applicativo non ha privilegi globali. Su NS8 però il volume `mariadb-data` e i volumi del container PHP stanno sullo stesso filesystem (`/dev/vda4`, verificato con `df` da entrambi i container e dall'host). Il servizio legge il parametro applicativo opzionale `dbDiskProbePath` (es. `/var/www/html`) e usa `disk_free_space()`; senza parametro vale il ripiego del design (si procede solo se i dati residui sono sotto 2 GB, con warning). Stima sul DB dev: audit con 30 giorni ≈ 27% di 7,2 GB ≈ 1,96 GB, al limite della soglia. Per questo la riga del CHANGELOG [DOPO IL DEPLOY] chiede di impostare il parametro.
10. **Se la cancellazione si ferma al tempo massimo l'ottimizzazione si rinvia** (stato `rinviato`): si evita di ricostruire due volte e di sforare il timeout del job (1800 s).
11. **Errore di lock:** con `lock_wait_timeout` scaduto MariaDB risponde con il codice 1205 (verificato su `soundorgan_test` con una transazione aperta che legge la tabella). È l'unico caso trattato come warning; qualsiasi altro errore dell'ALTER diventa stato `errore` (exit 1, notifica del dispatcher).
12. **Binlog spento sul dev** (`log_bin=OFF`): le DELETE grandi non producono binlog. Va verificato in produzione prima del deploy.
13. **`commands_spool.completed`** vale 0 (pendente/in esecuzione), 1 o -1 (concluso). La condizione `completed <> 0` esclude anche `NULL`, quindi le righe con `completed` NULL restano protette.
14. In `console` il Module di intelligence imposta già `controllerNamespace = 'sharkom\intelligence\commands'` (cartella oggi inesistente): **non serve toccare Module.php**. yii2-cron usa `sharkom\cron\commands`, quindi la route è `cron/maintenance/purge-spool`.

## Repo, branch e parallelismo

| Task | Repo (container `soundorgan-php`) | Branch | Dipende da |
|---|---|---|---|
| 0 | dev-helper, intelligence, yii2-cron, core | preparazione | — |
| 1-3 | `/var/www/html/vendor/sharkom/yii2-dev-helper` | `retention-pulizie` (nuovo, da `unified`) | 0 |
| 4 | `/var/www/html/vendor/sharkom/intelligence` | `fix-trigger-esclusioni` (vedi nota) | 0 |
| 5-6 | intelligence | `fix-trigger-esclusioni` | 3, 4 |
| 7-8 | `/var/www/html/vendor/sharkom/yii2-cron` | `retention-pulizie` | 0 |
| 9-10 | yii2-cron | `retention-pulizie` | 3, 7 |
| 11 | `/var/www/html` (core) | `dev-adminlte_v2-2.2.6` | 4, 8 |
| 12 | core | `dev-adminlte_v2-2.2.6` | 1-11 |
| 13 | tutti | — | 1-12 |

**Corsie parallele** (un agente per repo, mai due agenti nello stesso repo):
- Corsia A, dev-helper: 1 → 2 → 3.
- Corsia B, intelligence: 4 (migrazione, non usa dev-helper) subito; poi 5 → 6 quando la corsia A ha finito il Task 3.
- Corsia C, yii2-cron: 7 → 8 subito; poi 9 → 10 quando la corsia A ha finito il Task 3.
- Corsia D, core: 11 quando 4 e 8 sono committati; 12 alla fine; 13 come ultimo passo.

**Nota sul branch di intelligence.** Il working tree di intelligence è l'unico che il core carica e sta su `fix-trigger-esclusioni`, dove un altro agente ha modifiche non committate (`IntelligenceEventsHandler.php`, `models/IntelligenceTriggers.php`, `tests/unit/TriggerSuTabelleEscluseTest.php`). Non si può cambiare branch senza rompere il suo lavoro, e un worktree separato non verrebbe caricato dall'autoload di composer, quindi i test non lo vedrebbero. Il lavoro va quindi sullo stesso branch (stesso rilascio v2.2.5), con **solo file nuovi**. Ogni commit è limitato ai propri path (`git commit -m ... -- <path>`), così non include file che l'altro agente ha messo in stage. Non toccare: `src/Module.php`, `src/IntelligenceEventsHandler.php`, `src/components/Exclusions.php`, né gli altri file modificati dall'altro agente.

## Note operative (valgono per tutti i task)

- Tutti i comandi passano dal container: `podman exec [-u www-data] -w <dir> soundorgan-php ...`. Dall'host `/var/www/html` è `/home/ns8-soundorgan52/.local/share/containers/storage/volumes/soundorgan-src/_data`. Dopo aver creato un file: `podman exec soundorgan-php chown www-data:www-data <file>`.
- **I test girano solo su `soundorgan_test`**, sempre sotto `flock /tmp/soundorgan_test.lock` e come `www-data`. Non lanciare i comandi di pulizia né le migrazioni sul DB dev `soundorgan`.
- Commit con `git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit`, aggiungendo i trailer della sessione che esegue.
- Logging solo con `\sharkom\devhelper\LogHelper::log($livello, $msg, $categoria)`. Categorie: `db_maintenance` (dev-helper), `intelligence_retention`, `cron_retention`.
- Nessun testo di interfaccia: l'output dei comandi è per l'operatore, in italiano semplice, senza `Yii::t()`. Nessun importo, quindi niente MathPrecisionHelper (i rapporti tra byte sono stime, non importi).
- Compatibilità NormaFlow: il codice di `maintenance` usa solo `yii\db` e LogHelper; nessuna dipendenza da moduli solo-SoundOrgan. Le migrazioni controllano le tabelle prima di usarle.

---

### Task 0: Preparazione dei branch

**Files:** nessuno.

**Step 1: dev-helper — branch da unified**

```bash
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php bash -c 'git status --short; git rev-parse HEAD unified; git branch --show-current'
```
Expected: nessuna modifica; i due hash coincidono (oggi `6d4f894…`, branch corrente `audit-trail-firma`, identico a `unified`). Se lo stato non è pulito o HEAD ≠ unified: fermarsi e chiedere.

```bash
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git switch -c retention-pulizie unified
```
Expected: `Switched to a new branch 'retention-pulizie'` (il codice caricato dall'app non cambia).

**Step 2: yii2-cron**

```bash
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php bash -c 'git branch --show-current; git status --short'
```
Expected: `retention-pulizie`, nessuna modifica.

**Step 3: intelligence**

```bash
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git branch --show-current
```
Expected: `fix-trigger-esclusioni`. Se è un altro branch: fermarsi e chiedere.

**Step 4: core**

```bash
podman exec -w /var/www/html soundorgan-php bash -c 'git status --short | head; git rev-parse adminlte_v2 dev-adminlte_v2-2.2.6; git branch --show-current'
```
Expected: hash uguali, oppure `dev-adminlte_v2-2.2.6` avanti. Il core si porta su `dev-adminlte_v2-2.2.6` solo nel Task 11, se lo stato è pulito: `git switch dev-adminlte_v2-2.2.6`.

**Step 5: DB di test pronto**

```bash
podman exec soundorgan-php ls -la /tmp/soundorgan_test.lock /tmp/soundorgan_test.seeded
```
Expected: entrambi presenti. Se manca `.seeded`: `podman exec soundorgan-php bash /var/www/html/tests/setup-test-db.sh`.

---

### Task 1: Cancellazione a blocchi (ChunkedPurger) — dev-helper

Repo `yii2-dev-helper`, branch `retention-pulizie`.

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/PurgeResult.php`
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/ChunkedPurger.php`
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance/TestDb.php`
- Test: `/var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance/ChunkedPurgerTest.php`

**Step 1: Supporto dei test sul DB isolato**

`tests/unit/maintenance/TestDb.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\tests\unit\maintenance;

use RuntimeException;
use yii\db\Connection;

/**
 * Connessione al DB di test isolato soundorgan_test e tabelle di prova usa e getta.
 *
 * Mai il DB dell'applicazione: la guardia ferma tutto se common/config/test-local.php
 * punta altrove (stessa regex di common/tests/_db_guard.php).
 * Le prove della manutenzione fanno ALTER TABLE, che fa commit implicito: niente
 * transazioni, ogni prova crea e cancella la sua tabella.
 * Lanciare sempre sotto `flock /tmp/soundorgan_test.lock`.
 */
final class TestDb
{
    public static function connection(): Connection
    {
        $config = require dirname(__DIR__, 6) . '/common/config/test-local.php';
        $db = $config['components']['db'] ?? [];
        if (!preg_match('/(^|;|:)dbname=soundorgan_test(;|$)/', (string) ($db['dsn'] ?? ''))) {
            throw new RuntimeException('Test dev-helper: common/config/test-local.php non punta al DB di test soundorgan_test.');
        }
        unset($db['class']);
        $connection = new Connection($db);
        $connection->open();
        return $connection;
    }

    public static function createScratchTable(Connection $db, string $engine = 'InnoDB'): string
    {
        $table = 'zz_test_retention_' . getmypid() . '_' . bin2hex(random_bytes(3));
        $db->createCommand("CREATE TABLE `{$table}` (
            `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `d` DATETIME NULL,
            `tag` VARCHAR(50) NULL,
            KEY `d` (`d`)
        ) ENGINE={$engine}")->execute();
        return $table;
    }

    public static function dropTable(Connection $db, string $table): void
    {
        $db->createCommand("DROP TABLE IF EXISTS `{$table}`")->execute();
    }
}
```

**Step 2: Scrivere il test che fallisce**

`tests/unit/maintenance/ChunkedPurgerTest.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\tests\unit\maintenance;

use PHPUnit\Framework\TestCase;
use sharkom\devhelper\maintenance\ChunkedPurger;
use yii\db\Connection;
use yii\db\Query;

require_once __DIR__ . '/TestDb.php';

final class ChunkedPurgerTest extends TestCase
{
    private const SOGLIA = '2026-10-01 00:00:00';

    private Connection $db;
    private string $tabella;

    protected function setUp(): void
    {
        $this->db = TestDb::connection();
        $this->tabella = TestDb::createScratchTable($this->db);
    }

    protected function tearDown(): void
    {
        TestDb::dropTable($this->db, $this->tabella);
        $this->db->close();
    }

    private function inserisci(int $quante, string $data, string $tag): void
    {
        $righe = [];
        for ($i = 0; $i < $quante; $i++) {
            $righe[] = [$data, $tag];
        }
        $this->db->createCommand()->batchInsert($this->tabella, ['d', 'tag'], $righe)->execute();
    }

    private function conta(string $tag): int
    {
        return (int) (new Query())->from($this->tabella)->where(['tag' => $tag])->count('*', $this->db);
    }

    private function purger(): ChunkedPurger
    {
        $purger = new ChunkedPurger($this->db);
        $purger->batchSize = 5;
        $purger->pauseMs = 0;
        return $purger;
    }

    public function testCancellaABlocchiSoloLeRigheSottoLaSoglia(): void
    {
        $this->inserisci(12, '2026-01-01 00:00:00', 'vecchia');
        $this->inserisci(3, '2026-10-30 00:00:00', 'recente');

        $esito = $this->purger()->purge($this->tabella, ['<', 'd', self::SOGLIA], 'd', 0);

        $this->assertSame(12, $esito->deleted);
        $this->assertSame(3, $esito->batches);
        $this->assertFalse($esito->stoppedByTime);
        $this->assertSame(0, $this->conta('vecchia'));
        $this->assertSame(3, $this->conta('recente'));
    }

    public function testSiFermaAlTempoMassimo(): void
    {
        $this->inserisci(12, '2026-01-01 00:00:00', 'vecchia');
        $purger = $this->purger();
        $t = -10.0;
        // Ogni lettura dell'orologio avanza di 10 s: avvio 0, primo controllo 10 (< 15, blocco), secondo 20 (stop).
        $purger->clock = static function () use (&$t): float {
            $t += 10.0;
            return $t;
        };

        $esito = $purger->purge($this->tabella, ['<', 'd', self::SOGLIA], 'd', 15);

        $this->assertTrue($esito->stoppedByTime);
        $this->assertSame(1, $esito->batches);
        $this->assertSame(5, $esito->deleted);
        $this->assertSame(7, $this->conta('vecchia'));
    }

    public function testPausaTraIBlocchiMaNonDopoLUltimo(): void
    {
        $this->inserisci(12, '2026-01-01 00:00:00', 'vecchia');
        $purger = $this->purger();
        $purger->pauseMs = 7;
        $pause = [];
        $purger->sleeper = static function (int $microsecondi) use (&$pause): void {
            $pause[] = $microsecondi;
        };

        $purger->purge($this->tabella, ['<', 'd', self::SOGLIA], 'd', 0);

        $this->assertSame([7000, 7000], $pause);
    }

    public function testPassaLeRigheCancellateConLeColonneRichieste(): void
    {
        $this->inserisci(2, '2026-01-01 00:00:00', 'a');
        $this->inserisci(1, '2026-01-02 00:00:00', 'b');
        $this->inserisci(1, '2026-10-30 00:00:00', 'c');
        $visti = [];

        $this->purger()->purge(
            $this->tabella,
            ['<', 'd', self::SOGLIA],
            'd',
            0,
            ['tag'],
            static function (array $righe) use (&$visti): void {
                foreach ($righe as $riga) {
                    $visti[] = $riga['tag'];
                }
            }
        );

        $this->assertSame(['a', 'a', 'b'], $visti);
    }

    public function testNessunaRigaDaCancellare(): void
    {
        $this->inserisci(2, '2026-10-30 00:00:00', 'recente');

        $esito = $this->purger()->purge($this->tabella, ['<', 'd', self::SOGLIA], 'd', 0);

        $this->assertSame(0, $esito->deleted);
        $this->assertSame(0, $esito->batches);
        $this->assertSame(2, $this->conta('recente'));
    }
}
```

**Step 3: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/phpunit tests/unit/maintenance/ChunkedPurgerTest.php
```
Expected: ERRORS, `Class "sharkom\devhelper\maintenance\ChunkedPurger" not found`.

**Step 4: Implementazione minima**

`src/maintenance/PurgeResult.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

/**
 * Esito di una cancellazione a blocchi.
 */
final class PurgeResult
{
    /** Righe cancellate. */
    public int $deleted = 0;
    /** Blocchi eseguiti. */
    public int $batches = 0;
    /** Durata in secondi. */
    public float $seconds = 0.0;
    /** True se la cancellazione si e' fermata al tempo massimo: il resto alla prossima esecuzione. */
    public bool $stoppedByTime = false;
}
```

`src/maintenance/ChunkedPurger.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

use yii\db\Connection;
use yii\db\Query;

/**
 * Cancellazione a blocchi delle righe vecchie di una tabella grande.
 *
 * Per ogni blocco: SELECT degli id ordinati sulla colonna indicizzata della data
 * (le righe piu' vecchie per prime), poi DELETE ... WHERE id IN (...).
 * DELETE SQL diretta: nessun evento ActiveRecord, quindi niente audit ne' trigger
 * di intelligence. Tra un blocco e l'altro c'e' una pausa breve, per lasciare spazio
 * alle query dell'applicazione e alla purge di InnoDB. Al tempo massimo si ferma:
 * il resto alla prossima esecuzione.
 */
class ChunkedPurger
{
    /** Righe per blocco. */
    public int $batchSize = 5000;
    /** Pausa tra i blocchi, in millisecondi. */
    public int $pauseMs = 200;
    /** @var callable(): float orologio in secondi (sostituibile nei test) */
    public $clock;
    /** @var callable(int): void pausa in microsecondi (sostituibile nei test) */
    public $sleeper;

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $this->clock = static fn(): float => microtime(true);
        $this->sleeper = static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * @param string $table tabella con chiave primaria `id`
     * @param array|string $condition condizione nel formato di Query::where()
     * @param string $orderColumn colonna indicizzata usata per l'ordine
     * @param int $maxSeconds tempo massimo; 0 o negativo = nessun limite
     * @param string[] $extraColumns colonne lette insieme all'id e passate ad $afterBatch
     * @param callable|null $afterBatch function(array $righe): void, chiamata dopo la DELETE di ogni blocco
     */
    public function purge(
        string $table,
        $condition,
        string $orderColumn,
        int $maxSeconds,
        array $extraColumns = [],
        ?callable $afterBatch = null
    ): PurgeResult {
        $result = new PurgeResult();
        $start = ($this->clock)();

        while (true) {
            if ($maxSeconds > 0 && ($this->clock)() - $start >= $maxSeconds) {
                $result->stoppedByTime = true;
                break;
            }

            $rows = (new Query())
                ->select(array_merge(['id'], $extraColumns))
                ->from($table)
                ->where($condition)
                ->orderBy([$orderColumn => SORT_ASC, 'id' => SORT_ASC])
                ->limit($this->batchSize)
                ->all($this->db);
            if ($rows === []) {
                break;
            }

            $ids = array_map('intval', array_column($rows, 'id'));
            $result->deleted += $this->db->createCommand()->delete($table, ['id' => $ids])->execute();
            $result->batches++;

            if ($afterBatch !== null) {
                $afterBatch($rows);
            }
            if (count($rows) < $this->batchSize) {
                break;
            }
            if ($this->pauseMs > 0) {
                ($this->sleeper)($this->pauseMs * 1000);
            }
        }

        $result->seconds = round(($this->clock)() - $start, 3);
        return $result;
    }
}
```

**Step 5: Eseguire il test e vederlo passare**

Stesso comando dello Step 3. Expected: `OK (5 tests, ...)`.

**Step 6: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance /var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git add src/maintenance/PurgeResult.php src/maintenance/ChunkedPurger.php tests/unit/maintenance/TestDb.php tests/unit/maintenance/ChunkedPurgerTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: cancellazione a blocchi con tempo massimo per la manutenzione delle tabelle"
```

---

### Task 2: Ricostruzione online con soglie (OnlineTableOptimizer) — dev-helper

Repo `yii2-dev-helper`, branch `retention-pulizie`. Dipende dal Task 1 (TestDb).

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/OptimizeResult.php`
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/OnlineTableOptimizer.php`
- Test: `/var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance/OnlineTableOptimizerTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\tests\unit\maintenance;

use PHPUnit\Framework\TestCase;
use sharkom\devhelper\maintenance\OnlineTableOptimizer;
use sharkom\devhelper\maintenance\OptimizeResult;
use yii\db\Connection;

require_once __DIR__ . '/TestDb.php';

final class OnlineTableOptimizerTest extends TestCase
{
    private const GB = 1073741824;

    private Connection $db;
    /** @var string[] */
    private array $tabelle = [];

    protected function setUp(): void
    {
        $this->db = TestDb::connection();
    }

    protected function tearDown(): void
    {
        foreach ($this->tabelle as $tabella) {
            TestDb::dropTable($this->db, $tabella);
        }
        $this->db->close();
    }

    private function tabella(string $engine = 'InnoDB'): string
    {
        $tabella = TestDb::createScratchTable($this->db, $engine);
        $this->tabelle[] = $tabella;
        $righe = [];
        for ($i = 0; $i < 50; $i++) {
            $righe[] = ['2026-01-01 00:00:00', 'riga'];
        }
        $this->db->createCommand()->batchInsert($tabella, ['d', 'tag'], $righe)->execute();
        return $tabella;
    }

    /** Ottimizzatore con 100 GB liberi su disco e soglie azzerate: decide solo il resto. */
    private function ottimizzatoreSenzaSoglie(): OnlineTableOptimizer
    {
        $ottimizzatore = new OnlineTableOptimizer($this->db);
        $ottimizzatore->freeDiskProvider = static fn(): ?int => 100 * self::GB;
        $ottimizzatore->minFreeBytes = 0;
        $ottimizzatore->minFreeRatio = 0.0;
        return $ottimizzatore;
    }

    public function testStimaDelloSpazioLiberato(): void
    {
        $this->assertSame(700, OnlineTableOptimizer::estimateFreedBytes(1000, 70, 30));
        $this->assertSame(0, OnlineTableOptimizer::estimateFreedBytes(1000, 0, 30));
        $this->assertSame(0, OnlineTableOptimizer::estimateFreedBytes(0, 10, 0));
        $this->assertSame(1000, OnlineTableOptimizer::estimateFreedBytes(1000, 10, 0));
    }

    public function testSottoSogliaNessunaRicostruzione(): void
    {
        $tabella = $this->tabella();
        $ottimizzatore = new OnlineTableOptimizer($this->db);
        $ottimizzatore->freeDiskProvider = static fn(): ?int => 100 * self::GB;

        $esito = $ottimizzatore->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::BELOW_THRESHOLD, $esito->status);
        $this->assertSame(0, $esito->reclaimedBytes());
    }

    public function testRicostruzioneOnlineConSoglieRaggiunte(): void
    {
        $tabella = $this->tabella();

        $esito = $this->ottimizzatoreSenzaSoglie()->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::DONE, $esito->status, $esito->message);
        $this->assertGreaterThan(0, $esito->bytesBefore);
    }

    public function testSpazioDiscoInsufficiente(): void
    {
        $tabella = $this->tabella();
        $ottimizzatore = $this->ottimizzatoreSenzaSoglie();
        $ottimizzatore->freeDiskProvider = static fn(): ?int => 0;

        $esito = $ottimizzatore->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::DISK_SPACE, $esito->status);
    }

    public function testSpazioNonVerificabileEDatiResiduiSottoLaSogliaPrudente(): void
    {
        $tabella = $this->tabella();
        $ottimizzatore = $this->ottimizzatoreSenzaSoglie();
        $ottimizzatore->freeDiskProvider = static fn(): ?int => null;

        $esito = $ottimizzatore->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::DONE, $esito->status, $esito->message);
    }

    public function testSpazioNonVerificabileEDatiResiduiSopraLaSogliaPrudente(): void
    {
        $tabella = $this->tabella();
        $ottimizzatore = $this->ottimizzatoreSenzaSoglie();
        $ottimizzatore->freeDiskProvider = static fn(): ?int => null;
        $ottimizzatore->blindMaxBytes = 0;

        $esito = $ottimizzatore->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::DISK_SPACE, $esito->status);
        $this->assertStringContainsString('dbDiskProbePath', $esito->message);
    }

    public function testLockNonOttenutoEUnAvvisoENonUnErrore(): void
    {
        $tabella = $this->tabella();
        $prima = (int) $this->db->createCommand('SELECT @@SESSION.lock_wait_timeout')->queryScalar();

        // Una seconda connessione tiene aperta una transazione che ha letto la tabella:
        // il blocco dei metadati impedisce all'ALTER di partire.
        $altra = TestDb::connection();
        $transazione = $altra->beginTransaction();
        $altra->createCommand("SELECT COUNT(*) FROM `{$tabella}`")->queryScalar();
        try {
            $ottimizzatore = $this->ottimizzatoreSenzaSoglie();
            $ottimizzatore->lockWaitTimeout = 1;
            $esito = $ottimizzatore->optimize($tabella, 0);
        } finally {
            $transazione->rollBack();
            $altra->close();
        }

        $this->assertSame(OptimizeResult::LOCK_TIMEOUT, $esito->status);
        $this->assertSame($prima, (int) $this->db->createCommand('SELECT @@SESSION.lock_wait_timeout')->queryScalar());
    }

    public function testAlterNonOnlineEUnErrore(): void
    {
        // MEMORY non supporta ALGORITHM=INPLACE: l'ALTER viene rifiutato e non blocca la tabella.
        $tabella = $this->tabella('MEMORY');

        $esito = $this->ottimizzatoreSenzaSoglie()->optimize($tabella, 0);

        $this->assertSame(OptimizeResult::ERROR, $esito->status);
    }

    public function testPercorsoDiProvaPerLoSpazioLibero(): void
    {
        $ottimizzatore = new OnlineTableOptimizer($this->db);
        $ottimizzatore->freeDiskProvider = null;

        $ottimizzatore->diskProbePath = sys_get_temp_dir();
        $this->assertIsInt($ottimizzatore->freeDiskBytes());

        $ottimizzatore->diskProbePath = '/percorso/che/non/esiste';
        $this->assertNull($ottimizzatore->freeDiskBytes());

        $ottimizzatore->diskProbePath = null;
        $this->assertNull($ottimizzatore->freeDiskBytes());
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/phpunit tests/unit/maintenance/OnlineTableOptimizerTest.php
```
Expected: ERRORS, `Class "sharkom\devhelper\maintenance\OnlineTableOptimizer" not found`.

**Step 3: Implementazione minima**

`src/maintenance/OptimizeResult.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

/**
 * Esito del tentativo di ricostruzione online di una tabella.
 */
final class OptimizeResult
{
    public const DONE = 'eseguito';
    public const BELOW_THRESHOLD = 'sotto_soglia';
    public const DISK_SPACE = 'spazio_disco';
    public const LOCK_TIMEOUT = 'lock_non_ottenuto';
    public const POSTPONED = 'rinviato';
    public const ERROR = 'errore';

    public string $status;
    public string $message;
    /** data_length + index_length prima della ricostruzione. */
    public int $bytesBefore = 0;
    /** data_length + index_length dopo la ricostruzione (solo se eseguita). */
    public int $bytesAfter = 0;
    /** Spazio libero stimato usato per la decisione. */
    public int $freeBytesEstimate = 0;
    public float $seconds = 0.0;

    public function __construct(string $status, string $message)
    {
        $this->status = $status;
        $this->message = $message;
    }

    public function reclaimedBytes(): int
    {
        return $this->status === self::DONE ? max(0, $this->bytesBefore - $this->bytesAfter) : 0;
    }
}
```

`src/maintenance/OnlineTableOptimizer.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

use sharkom\devhelper\LogHelper;
use Yii;
use yii\db\Connection;
use yii\db\Exception as DbException;

/**
 * Ricostruzione online di una tabella InnoDB per restituire al filesystem lo spazio
 * delle righe cancellate (con innodb_file_per_table lo spazio resta nel file .ibd
 * finche' la tabella non viene ricostruita).
 *
 * ALTER TABLE <t> FORCE, ALGORITHM=INPLACE, LOCK=NONE: online; se non si puo' fare
 * senza bloccare la tabella, MariaDB rifiuta di partire. lock_wait_timeout sulla
 * sessione: se il blocco breve iniziale/finale non arriva entro N secondi rinuncia
 * (avviso) senza mettere in coda le query dell'applicazione.
 *
 * Si ricostruisce solo se:
 *  - lo spazio liberato stimato supera $minFreeBytes e $minFreeRatio della tabella;
 *  - lo spazio libero del filesystem del datadir supera i dati residui piu' un margine.
 *    Il DB e' in un altro container: se lo spazio non e' verificabile si procede solo
 *    con dati residui sotto $blindMaxBytes. Il parametro applicativo dbDiskProbePath
 *    indica una cartella sullo stesso filesystem del datadir (su NS8 i volumi del
 *    modulo stanno tutti sullo stesso disco, es. /var/www/html).
 */
class OnlineTableOptimizer
{
    /** Spazio liberato minimo (1 GB). */
    public int $minFreeBytes = 1073741824;
    /** Quota minima dello spazio liberato sulla tabella (30%). */
    public float $minFreeRatio = 0.30;
    /** Senza spazio su disco verificabile si procede solo sotto questi dati residui (2 GB). */
    public int $blindMaxBytes = 2147483648;
    /** Margine oltre ai dati residui richiesto sul filesystem (1 GB). */
    public int $diskMarginBytes = 1073741824;
    /** Attesa massima del blocco dei metadati, in secondi. */
    public int $lockWaitTimeout = 10;
    /** Cartella sullo stesso filesystem del datadir; null = non verificabile. */
    public ?string $diskProbePath = null;
    /** @var callable|null function(): ?int byte liberi del datadir (sostituibile nei test) */
    public $freeDiskProvider;
    public string $logCategory = 'db_maintenance';

    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
        $param = Yii::$app !== null ? (Yii::$app->params['dbDiskProbePath'] ?? null) : null;
        $this->diskProbePath = is_string($param) && $param !== '' ? $param : null;
    }

    /**
     * Stima dello spazio liberato da una cancellazione, proporzionale alle righe tolte.
     * Serve perche' subito dopo una DELETE grande data_free non e' ancora cresciuto
     * (la purge di InnoDB e' asincrona).
     */
    public static function estimateFreedBytes(int $totalBytesBefore, int $deletedRows, int $rowsAfter): int
    {
        $rowsBefore = $deletedRows + $rowsAfter;
        if ($totalBytesBefore <= 0 || $deletedRows <= 0 || $rowsBefore <= 0) {
            return 0;
        }
        return (int) floor($totalBytesBefore * ($deletedRows / $rowsBefore));
    }

    /**
     * @return array{data_length:int, index_length:int, data_free:int}
     */
    public function tableStats(string $table): array
    {
        $row = $this->db->createCommand(
            'SELECT DATA_LENGTH, INDEX_LENGTH, DATA_FREE FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
            [':t' => $table]
        )->queryOne();

        return [
            'data_length' => (int) ($row['DATA_LENGTH'] ?? 0),
            'index_length' => (int) ($row['INDEX_LENGTH'] ?? 0),
            'data_free' => (int) ($row['DATA_FREE'] ?? 0),
        ];
    }

    public function freeDiskBytes(): ?int
    {
        if ($this->freeDiskProvider !== null) {
            $value = ($this->freeDiskProvider)();
            return $value === null ? null : (int) $value;
        }
        if ($this->diskProbePath === null || !is_dir($this->diskProbePath)) {
            return null;
        }
        $free = @disk_free_space($this->diskProbePath);
        return $free === false ? null : (int) $free;
    }

    public function optimize(string $table, int $estimatedFreedBytes = 0): OptimizeResult
    {
        $stats = $this->tableStats($table);
        $total = $stats['data_length'] + $stats['index_length'];
        $free = max($stats['data_free'], $estimatedFreedBytes);

        if ($total <= 0 || $free < $this->minFreeBytes || $free < $this->minFreeRatio * $total) {
            return $this->finish(new OptimizeResult(
                OptimizeResult::BELOW_THRESHOLD,
                'spazio liberato stimato ' . self::mb($free) . ' su ' . self::mb($total) . ': sotto soglia'
            ), $table, $total, $free);
        }

        $residual = max(0, $total - $free);
        $disk = $this->freeDiskBytes();
        if ($disk === null) {
            if ($residual > $this->blindMaxBytes) {
                return $this->finish(new OptimizeResult(
                    OptimizeResult::DISK_SPACE,
                    'spazio libero del datadir non verificabile e dati residui ' . self::mb($residual)
                    . ' sopra ' . self::mb($this->blindMaxBytes) . ': ricostruzione rinviata (impostare il parametro dbDiskProbePath)'
                ), $table, $total, $free);
            }
            LogHelper::log(
                'warning',
                "{$table}: spazio libero del datadir non verificabile, dati residui " . self::mb($residual) . ' sotto la soglia prudente: procedo',
                $this->logCategory
            );
        } elseif ($disk < $residual + $this->diskMarginBytes) {
            return $this->finish(new OptimizeResult(
                OptimizeResult::DISK_SPACE,
                'spazio libero ' . self::mb($disk) . ' insufficiente per dati residui ' . self::mb($residual)
                . ' piu\' margine ' . self::mb($this->diskMarginBytes)
            ), $table, $total, $free);
        }

        $previous = (int) $this->db->createCommand('SELECT @@SESSION.lock_wait_timeout')->queryScalar();
        $this->db->createCommand('SET SESSION lock_wait_timeout = ' . max(1, $this->lockWaitTimeout))->execute();
        $start = microtime(true);
        try {
            $this->db->createCommand(
                'ALTER TABLE ' . $this->db->quoteTableName($table) . ' FORCE, ALGORITHM=INPLACE, LOCK=NONE'
            )->execute();
            $after = $this->tableStats($table);
            $result = new OptimizeResult(OptimizeResult::DONE, 'ricostruzione online completata');
            $result->bytesAfter = $after['data_length'] + $after['index_length'];
        } catch (DbException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            $result = $code === 1205
                ? new OptimizeResult(
                    OptimizeResult::LOCK_TIMEOUT,
                    "blocco della tabella non ottenuto entro {$this->lockWaitTimeout} s: riprovo alla prossima esecuzione"
                )
                : new OptimizeResult(OptimizeResult::ERROR, "ALTER TABLE non riuscito (codice {$code}): " . $e->getMessage());
        } finally {
            // Il comando gira anche dentro il worker del dispatcher: la sessione torna com'era.
            $this->db->createCommand('SET SESSION lock_wait_timeout = ' . $previous)->execute();
        }
        $result->seconds = round(microtime(true) - $start, 3);

        return $this->finish($result, $table, $total, $free);
    }

    private function finish(OptimizeResult $result, string $table, int $total, int $free): OptimizeResult
    {
        $result->bytesBefore = $total;
        $result->freeBytesEstimate = $free;
        $levels = [
            OptimizeResult::DONE => 'info',
            OptimizeResult::BELOW_THRESHOLD => 'info',
            OptimizeResult::POSTPONED => 'info',
            OptimizeResult::DISK_SPACE => 'warning',
            OptimizeResult::LOCK_TIMEOUT => 'warning',
            OptimizeResult::ERROR => 'error',
        ];
        LogHelper::log(
            $levels[$result->status] ?? 'info',
            "{$table}: ottimizzazione {$result->status}: {$result->message}",
            $this->logCategory
        );
        return $result;
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 0, ',', '.') . ' MB';
    }
}
```

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (9 tests, ...)`. Il test del lock dura circa 1 s.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance /var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git add src/maintenance/OptimizeResult.php src/maintenance/OnlineTableOptimizer.php tests/unit/maintenance/OnlineTableOptimizerTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: ricostruzione online delle tabelle con soglie, controllo dello spazio e lock_wait_timeout"
```

---

### Task 3: Retention di una tabella e report (TableRetention) — dev-helper

Repo `yii2-dev-helper`, branch `retention-pulizie`. Dipende dai Task 1-2. **Sblocca i Task 5, 6, 9, 10.**

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/RetentionReport.php`
- Create: `/var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/TableRetention.php`
- Test: `/var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance/TableRetentionTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\tests\unit\maintenance;

use PHPUnit\Framework\TestCase;
use sharkom\devhelper\maintenance\OptimizeResult;
use sharkom\devhelper\maintenance\RetentionReport;
use sharkom\devhelper\maintenance\TableRetention;
use yii\db\Connection;

require_once __DIR__ . '/TestDb.php';

final class TableRetentionTest extends TestCase
{
    private const SOGLIA = '2026-10-01 00:00:00';

    private Connection $db;
    private string $tabella;

    protected function setUp(): void
    {
        $this->db = TestDb::connection();
        $this->tabella = TestDb::createScratchTable($this->db);
        $righe = [];
        for ($i = 0; $i < 12; $i++) {
            $righe[] = ['2026-01-01 00:00:00', 'vecchia'];
        }
        for ($i = 0; $i < 3; $i++) {
            $righe[] = ['2026-10-30 00:00:00', 'recente'];
        }
        $this->db->createCommand()->batchInsert($this->tabella, ['d', 'tag'], $righe)->execute();
    }

    protected function tearDown(): void
    {
        TestDb::dropTable($this->db, $this->tabella);
        $this->db->close();
    }

    private function retention(): TableRetention
    {
        $retention = new TableRetention($this->db, 'test_retention');
        $retention->purger->batchSize = 5;
        $retention->purger->pauseMs = 0;
        $retention->optimizer->freeDiskProvider = static fn(): ?int => 100 * 1073741824;
        return $retention;
    }

    private function esegui(TableRetention $retention, int $maxSecondi, bool $ottimizza): RetentionReport
    {
        return $retention->run($this->tabella, ['<', 'd', self::SOGLIA], 'd', self::SOGLIA, $maxSecondi, $ottimizza);
    }

    public function testSenzaOttimizzazione(): void
    {
        $report = $this->esegui($this->retention(), 0, false);

        $this->assertSame(12, $report->purge->deleted);
        $this->assertNull($report->optimize);
        $this->assertFalse($report->isError());
        $this->assertStringContainsString('cancellate 12 righe precedenti a ' . self::SOGLIA, $report->summary());
        $this->assertStringContainsString('ottimizzazione non richiesta', $report->summary());
    }

    public function testConOttimizzazioneSoglieRaggiunte(): void
    {
        $retention = $this->retention();
        $retention->optimizer->minFreeBytes = 0;
        $retention->optimizer->minFreeRatio = 0.0;

        $report = $this->esegui($retention, 0, true);

        $this->assertSame(OptimizeResult::DONE, $report->optimize->status, $report->optimize->message);
        $this->assertStringContainsString('ottimizzazione: eseguito', $report->summary());
        $this->assertStringContainsString('spazio recuperato', $report->summary());
    }

    public function testTempoMassimoRinviaLOttimizzazione(): void
    {
        $retention = $this->retention();
        $t = -10.0;
        $retention->purger->clock = static function () use (&$t): float {
            $t += 10.0;
            return $t;
        };

        $report = $this->esegui($retention, 15, true);

        $this->assertTrue($report->purge->stoppedByTime);
        $this->assertSame(OptimizeResult::POSTPONED, $report->optimize->status);
        $this->assertStringContainsString('tempo massimo', $report->summary());
    }

    public function testErroreDiOttimizzazione(): void
    {
        $report = new RetentionReport('t', self::SOGLIA);
        $report->optimize = new OptimizeResult(OptimizeResult::ERROR, 'prova');

        $this->assertTrue($report->isError());
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/phpunit tests/unit/maintenance/TableRetentionTest.php
```
Expected: ERRORS, `Class "sharkom\devhelper\maintenance\TableRetention" not found`.

**Step 3: Implementazione minima**

`src/maintenance/RetentionReport.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

/**
 * Report di una pulizia per retention: cancellazione, file rimossi, ottimizzazione.
 */
final class RetentionReport
{
    public string $table;
    public string $cutoff;
    public PurgeResult $purge;
    /** null = ottimizzazione non richiesta. */
    public ?OptimizeResult $optimize = null;
    /** File collegati rimossi (solo per le tabelle che ne hanno). */
    public int $filesRemoved = 0;

    public function __construct(string $table, string $cutoff)
    {
        $this->table = $table;
        $this->cutoff = $cutoff;
        $this->purge = new PurgeResult();
    }

    public function isError(): bool
    {
        return $this->optimize !== null && $this->optimize->status === OptimizeResult::ERROR;
    }

    /** Riga finale per log e console. */
    public function summary(): string
    {
        $parts = [
            "{$this->table}: cancellate {$this->purge->deleted} righe precedenti a {$this->cutoff} in {$this->purge->batches} blocchi",
            "file rimossi {$this->filesRemoved}",
            'durata ' . number_format($this->purge->seconds, 1, ',', '') . ' s'
                . ($this->purge->stoppedByTime ? ' (tempo massimo raggiunto, il resto alla prossima esecuzione)' : ''),
        ];
        if ($this->optimize === null) {
            $parts[] = 'ottimizzazione non richiesta';
        } else {
            $parts[] = "ottimizzazione: {$this->optimize->status} ({$this->optimize->message})";
            $parts[] = 'spazio recuperato ' . number_format($this->optimize->reclaimedBytes() / 1048576, 0, ',', '.') . ' MB';
        }
        return implode(', ', $parts);
    }
}
```

`src/maintenance/TableRetention.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\devhelper\maintenance;

use sharkom\devhelper\LogHelper;
use yii\db\Connection;
use yii\db\Query;

/**
 * Pulizia per retention di una tabella: cancellazione a blocchi e, se richiesta,
 * ricostruzione online con soglia. I servizi dei pacchetti forniscono solo la
 * condizione delle righe da cancellare e l'eventuale gestione dei file collegati.
 */
class TableRetention
{
    public ChunkedPurger $purger;
    public OnlineTableOptimizer $optimizer;

    private Connection $db;
    private string $logCategory;

    public function __construct(
        Connection $db,
        string $logCategory,
        ?ChunkedPurger $purger = null,
        ?OnlineTableOptimizer $optimizer = null
    ) {
        $this->db = $db;
        $this->logCategory = $logCategory;
        $this->purger = $purger ?? new ChunkedPurger($db);
        $this->optimizer = $optimizer ?? new OnlineTableOptimizer($db);
        $this->optimizer->logCategory = $logCategory;
    }

    /**
     * @param array|string $condition condizione delle righe da cancellare (Query::where())
     * @param string $orderColumn colonna indicizzata della data
     * @param string $cutoff soglia leggibile, solo per log e report
     * @param string[] $extraColumns colonne passate ad $afterBatch
     */
    public function run(
        string $table,
        $condition,
        string $orderColumn,
        string $cutoff,
        int $maxSeconds,
        bool $optimize,
        array $extraColumns = [],
        ?callable $afterBatch = null
    ): RetentionReport {
        $before = $this->optimizer->tableStats($table);
        $totalBefore = $before['data_length'] + $before['index_length'];
        LogHelper::log('info', "{$table}: pulizia delle righe precedenti a {$cutoff} (tempo massimo {$maxSeconds} s)", $this->logCategory);

        $report = new RetentionReport($table, $cutoff);
        $report->purge = $this->purger->purge($table, $condition, $orderColumn, $maxSeconds, $extraColumns, $afterBatch);

        if (!$optimize) {
            return $report;
        }
        if ($report->purge->stoppedByTime) {
            $report->optimize = new OptimizeResult(
                OptimizeResult::POSTPONED,
                'pulizia non completata entro il tempo massimo: ottimizzazione alla prossima esecuzione'
            );
            LogHelper::log('info', "{$table}: {$report->optimize->message}", $this->logCategory);
            return $report;
        }

        $rowsAfter = (int) (new Query())->from($table)->count('*', $this->db);
        $report->optimize = $this->optimizer->optimize(
            $table,
            OnlineTableOptimizer::estimateFreedBytes($totalBefore, $report->purge->deleted, $rowsAfter)
        );
        return $report;
    }
}
```

**Step 4: Eseguire i test della cartella**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/phpunit tests/unit/maintenance
```
Expected: `OK (18 tests, ...)`.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance /var/www/html/vendor/sharkom/yii2-dev-helper/tests/unit/maintenance
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git add src/maintenance/RetentionReport.php src/maintenance/TableRetention.php tests/unit/maintenance/TableRetentionTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: retention di una tabella con cancellazione a blocchi, ottimizzazione e report"
```

---

### Task 4: Migrazione che programma purge-audit — intelligence

Repo `intelligence`, branch `fix-trigger-esclusioni`, solo file nuovi. Non dipende da dev-helper: si può fare subito.

**Files:**
- Create: `/var/www/html/vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php`
- Test: `/var/www/html/vendor/sharkom/intelligence/tests/unit/SchedulePurgeAuditMigrationTest.php`

**Step 0:** `podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git branch --show-current`. Expected: `fix-trigger-esclusioni`.

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\intelligence\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

require_once dirname(__DIR__, 2) . '/src/migrations/m261102_160000_schedule_purge_audit.php';

/**
 * Migrazione che programma intelligence/maintenance/purge-audit: tre rami
 * (dispatcher, cron_job di yii2-cron, nessuno), idempotenza e down.
 * Tutto in una transazione annullata in _after, su soundorgan_test.
 */
final class SchedulePurgeAuditMigrationTest extends Unit
{
    private const MIGRAZIONE = \m261102_160000_schedule_purge_audit::class;

    private ?Transaction $transazione = null;
    private $logLevel;

    protected function _before(): void
    {
        $this->logLevel = Yii::$app->params['LogLevel'] ?? null;
        Yii::$app->params['LogLevel'] = 'error';
        $this->transazione = Yii::$app->db->beginTransaction();
        ob_start();
    }

    protected function _after(): void
    {
        ob_end_clean();
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->params['LogLevel'] = $this->logLevel;
    }

    private function migrazione(bool $conDispatcher = true, bool $conCron = true): \m261102_160000_schedule_purge_audit
    {
        return new class($conDispatcher, $conCron) extends \m261102_160000_schedule_purge_audit {
            private bool $conDispatcher;
            private bool $conCron;

            public function __construct(bool $conDispatcher, bool $conCron)
            {
                $this->conDispatcher = $conDispatcher;
                $this->conCron = $conCron;
                parent::__construct(['db' => Yii::$app->db]);
            }

            protected function jobDispatcherAvailable(): bool
            {
                return $this->conDispatcher && parent::jobDispatcherAvailable();
            }

            protected function cronJobAvailable(): bool
            {
                return $this->conCron && parent::cronJobAvailable();
            }
        };
    }

    private function job(): ?array
    {
        $job = (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->one();
        return $job === false ? null : $job;
    }

    private function definizioni(int $jobId): array
    {
        return (new Query())->from('schedule_definitions')->where(['system_job_id' => $jobId])->indexBy('rrule')->all();
    }

    private function cronJob(): array
    {
        return (new Query())->from('cron_job')->where(['like', 'command', (self::MIGRAZIONE)::ROUTE])->all();
    }

    public function testConDispatcherCreaJobNotturnoEdEsecuzioneUnicaMaterializzata(): void
    {
        $prima = time();

        $this->migrazione()->up();

        $job = $this->job();
        $this->assertNotNull($job);
        $this->assertSame('console.run', $job['job_type_code']);
        $this->assertSame(
            ['route' => 'intelligence/maintenance/purge-audit', 'args' => ['--optimize=1']],
            json_decode($job['params'], true)
        );
        $this->assertSame(3600, (int) $job['catch_up_threshold_seconds']);
        $this->assertSame(1800, (int) $job['timeout']);
        $this->assertSame('skip', $job['overlap_policy']);

        $definizioni = $this->definizioni((int) $job['id']);
        $this->assertEqualsCanonicalizing(
            [(self::MIGRAZIONE)::RRULE_NOTTE, (self::MIGRAZIONE)::RRULE_UNICA],
            array_keys($definizioni)
        );

        $unica = $definizioni[(self::MIGRAZIONE)::RRULE_UNICA];
        $dtstart = (new DateTimeImmutable($unica['dtstart'], new DateTimeZone('Europe/Rome')))->getTimestamp();
        $this->assertGreaterThanOrEqual($prima + 115, $dtstart);
        $this->assertLessThanOrEqual(time() + 125, $dtstart);

        // La schedule unica e' gia' materializzata: senza, il materializzatore notturno la perderebbe.
        $eventi = (new Query())->from('scheduled_events')->where(['schedule_definition_id' => $unica['id']])->all();
        $this->assertCount(1, $eventi);
        $this->assertSame('pending', $eventi[0]['status']);
        $this->assertSame($unica['dtstart'], $eventi[0]['run_at']);

        $this->assertSame([], $this->cronJob(), 'con il dispatcher non si crea il cron_job');
    }

    public function testIdempotente(): void
    {
        $this->migrazione()->up();
        $this->migrazione()->up();

        $this->assertSame(1, (int) (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->count());
        $job = $this->job();
        $this->assertCount(2, $this->definizioni((int) $job['id']));
        $this->assertSame(1, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count());
    }

    public function testDownRimuoveJobESchedule(): void
    {
        $this->migrazione()->up();
        $jobId = (int) $this->job()['id'];

        $this->migrazione()->down();

        $this->assertNull($this->job());
        $this->assertSame([], $this->definizioni($jobId));
    }

    public function testSenzaDispatcherCreaIlCronJobGiornaliero(): void
    {
        $this->migrazione(false, true)->up();
        $this->migrazione(false, true)->up();

        $righe = $this->cronJob();
        $this->assertCount(1, $righe);
        $this->assertSame('30 2 * * *', $righe[0]['schedule']);
        $this->assertSame(1, (int) $righe[0]['active']);
        $this->assertStringStartsWith(PHP_BINARY . ' ', $righe[0]['command']);
        $this->assertStringEndsWith('yii intelligence/maintenance/purge-audit --optimize=1', $righe[0]['command']);
        $this->assertNull($this->job());

        $this->migrazione(false, true)->down();
        $this->assertSame([], $this->cronJob());
    }

    public function testSenzaDispatcherNeCronNessunaAzione(): void
    {
        $this->assertTrue($this->migrazione(false, false)->up());

        $this->assertNull($this->job());
        $this->assertSame([], $this->cronJob());
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/intelligence/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit SchedulePurgeAuditMigrationTest
```
Expected: errore `Failed opening required '.../m261102_160000_schedule_purge_audit.php'`.

**Step 3: Implementazione minima**

`src/migrations/m261102_160000_schedule_purge_audit.php`:

```php
<?php

use yii\db\Migration;
use yii\db\Query;

/**
 * Programma la pulizia di intelligence_db_audit (retention 30 giorni) con il comando
 * `intelligence/maintenance/purge-audit --optimize=1`.
 *
 * Tre rami:
 *  - job dispatcher installato (SoundOrgan): system job "Manutenzione: pulizia audit
 *    intelligence" (console.run) con due schedule: ogni notte alle 2:30 e
 *    un'esecuzione unica (COUNT=1) due minuti dopo la migrazione, per la prima pulizia
 *    con ottimizzazione subito dopo il deploy ma fuori dal tempo delle migrazioni.
 *    L'esecuzione unica viene materializzata subito: il materializzatore notturno
 *    (2:00) non crea occorrenze nel passato e la perderebbe;
 *  - niente dispatcher ma tabella cron_job di yii2-cron (NormaFlow): cron_job
 *    giornaliero "30 2 * * *";
 *  - nessuno dei due: nessuna azione.
 *
 * SCELTE: catch_up_threshold_seconds = 3600 (se il dispatcher e' fermo durante il
 * deploy, l'esecuzione unica parte se riparte entro un'ora; oltre ci pensa la notte);
 * overlap_policy = skip; max_retries = 0 (il retry e' la notte dopo); timeout = 1800
 * (20 minuti di cancellazione piu' la ricostruzione); internal = 0 (visibile in
 * calendario).
 *
 * ENCODING di params: json_encode SINGOLO (vedi m260728_120041).
 *
 * Niente transazione (up(), non safeUp()): il Materializer sveglia il dispatcher via
 * Redis appena inserito l'evento, e dentro una transazione aperta il dispatcher non
 * lo vedrebbe. Ogni passo e' idempotente: job per nome, schedule per rrule, cron_job
 * per comando. Non fa mai fallire il deploy per la materializzazione: scrive un avviso.
 */
class m261102_160000_schedule_purge_audit extends Migration
{
    public const JOB_NAME = 'Manutenzione: pulizia audit intelligence';
    public const ROUTE = 'intelligence/maintenance/purge-audit';
    public const ARGS = ['--optimize=1'];
    public const RRULE_NOTTE = 'FREQ=DAILY;BYHOUR=2;BYMINUTE=30;BYSECOND=0';
    public const RRULE_UNICA = 'FREQ=DAILY;COUNT=1';
    public const CRON_SCHEDULE = '30 2 * * *';
    public const RITARDO_PRIMA_ESECUZIONE = 120;

    private const TYPE_CODE = 'console.run';
    private const TIMEZONE = 'Europe/Rome';
    private const DESCRIZIONE = 'Cancella a blocchi le righe di intelligence_db_audit piu\' vecchie di 30 giorni '
        . 'e, se conviene, ricostruisce la tabella online per recuperare lo spazio.';

    public function up()
    {
        if ($this->jobDispatcherAvailable()) {
            $this->scheduleWithDispatcher();
            return true;
        }
        if ($this->cronJobAvailable()) {
            $this->scheduleWithCronJob();
            return true;
        }
        echo "    > ne' job dispatcher ne' yii2-cron: nessuna pulizia programmata per " . self::ROUTE . "\n";
        return true;
    }

    public function down()
    {
        if ($this->jobDispatcherAvailable()) {
            $jobId = (int) $this->findJobId();
            if ($jobId) {
                $this->delete('{{%schedule_definitions}}', ['system_job_id' => $jobId]);
                $this->delete('{{%system_jobs}}', ['id' => $jobId]);
            }
        }
        if ($this->cronJobAvailable()) {
            $this->delete('{{%cron_job}}', ['like', 'command', self::ROUTE]);
        }
        return true;
    }

    private function scheduleWithDispatcher(): void
    {
        $now = time();
        $jobId = (int) $this->findJobId();
        if ($jobId) {
            echo "    > job '" . self::JOB_NAME . "' gia' presente (id {$jobId})\n";
        } else {
            $this->insert('{{%system_jobs}}', [
                'job_type_code' => self::TYPE_CODE,
                'name' => self::JOB_NAME,
                'description' => self::DESCRIZIONE,
                'params' => json_encode(['route' => self::ROUTE, 'args' => self::ARGS], JSON_UNESCAPED_SLASHES),
                'timeout' => 1800,
                'max_retries' => 0,
                'lane' => 'default',
                'overlap_policy' => 'skip',
                'catch_up_threshold_seconds' => 3600,
                'notify_on_failure_to' => 'dev@sharkom.net',
                'enabled' => 1,
                'internal' => 0,
                'simulate_in_dev' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $jobId = (int) $this->db->getLastInsertID();
            echo "    + job '" . self::JOB_NAME . "' creato (id {$jobId})\n";
        }

        $adesso = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
        if (!$this->hasDefinition($jobId, self::RRULE_NOTTE)) {
            $this->insertDefinition($jobId, self::RRULE_NOTTE, $adesso, $now);
        }
        if ($this->hasDefinition($jobId, self::RRULE_UNICA)) {
            echo "    > esecuzione unica gia' programmata per job_id={$jobId}\n";
            return;
        }
        $definitionId = $this->insertDefinition(
            $jobId,
            self::RRULE_UNICA,
            $adesso->modify('+' . self::RITARDO_PRIMA_ESECUZIONE . ' seconds'),
            $now
        );
        $this->materialize($definitionId);
    }

    private function insertDefinition(int $jobId, string $rrule, DateTimeImmutable $dtstart, int $now): int
    {
        $this->insert('{{%schedule_definitions}}', [
            'system_job_id' => $jobId,
            'rrule' => $rrule,
            'dtstart' => $dtstart->format('Y-m-d H:i:s'),
            'until' => null,
            'timezone' => self::TIMEZONE,
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $this->db->getLastInsertID();
        echo "    + schedule {$rrule} (dtstart {$dtstart->format('Y-m-d H:i:s')}) per job_id={$jobId}\n";
        return $id;
    }

    private function materialize(int $definitionId): void
    {
        $manuale = 'lanciare a mano: php yii ' . self::ROUTE . ' ' . implode(' ', self::ARGS);
        $class = '\\sharkom\\jobDispatcher\\services\\Materializer';
        if (!class_exists($class)) {
            echo "    ! Materializer del dispatcher non disponibile: esecuzione unica non pianificata, {$manuale}\n";
            return;
        }
        // Il Materializer legge la definition con ActiveRecord, cioe' su Yii::$app->db:
        // con `migrate --db=<altro>` scriverebbe sul DB sbagliato.
        if ($this->db !== Yii::$app->db) {
            echo "    > connessione diversa da Yii::\$app->db: esecuzione unica non materializzata, {$manuale}\n";
            return;
        }
        try {
            $report = (new $class(null, $this->db))->rematerializeForDefinition($definitionId, 1);
        } catch (\Throwable $e) {
            echo "    ! materializzazione dell'esecuzione unica non riuscita: {$e->getMessage()}; {$manuale}\n";
            return;
        }
        if (!empty($report->errors)) {
            echo "    ! materializzazione dell'esecuzione unica non riuscita: " . implode('; ', $report->errors) . "; {$manuale}\n";
            return;
        }
        echo "    + esecuzione unica pianificata ({$report->eventsInserted} evento)\n";
    }

    private function scheduleWithCronJob(): void
    {
        $exists = (new Query())->from('{{%cron_job}}')->where(['like', 'command', self::ROUTE])->exists($this->db);
        if ($exists) {
            echo "    > cron_job per " . self::ROUTE . " gia' presente\n";
            return;
        }
        $this->insert('{{%cron_job}}', [
            'name' => self::JOB_NAME,
            'schedule' => self::CRON_SCHEDULE,
            'command' => PHP_BINARY . ' ' . $this->yiiScript() . ' ' . self::ROUTE . ' ' . implode(' ', self::ARGS),
            'logfile' => '',
            'active' => 1,
        ]);
        echo "    + cron_job '" . self::JOB_NAME . "' (" . self::CRON_SCHEDULE . ")\n";
    }

    private function yiiScript(): string
    {
        $path = Yii::getAlias('@vendor/../yii');
        $real = realpath($path);
        return $real !== false ? $real : $path;
    }

    private function findJobId()
    {
        return (new Query())
            ->select('id')->from('{{%system_jobs}}')->where(['name' => self::JOB_NAME])->scalar($this->db);
    }

    private function hasDefinition(int $jobId, string $rrule): bool
    {
        return (new Query())
            ->from('{{%schedule_definitions}}')
            ->where(['system_job_id' => $jobId, 'rrule' => $rrule])
            ->exists($this->db);
    }

    protected function jobDispatcherAvailable(): bool
    {
        $tables = $this->db->schema->getTableNames();
        return in_array('system_jobs', $tables, true)
            && in_array('schedule_definitions', $tables, true)
            && in_array('system_job_types', $tables, true)
            && (new Query())
                ->from('{{%system_job_types}}')->where(['code' => self::TYPE_CODE])->exists($this->db);
    }

    protected function cronJobAvailable(): bool
    {
        return $this->db->getTableSchema('{{%cron_job}}', true) !== null;
    }
}
```

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (5 tests, ...)`.

**Step 5: Commit (solo i propri path)**

```bash
podman exec soundorgan-php chown www-data:www-data /var/www/html/vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php /var/www/html/vendor/sharkom/intelligence/tests/unit/SchedulePurgeAuditMigrationTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git add src/migrations/m261102_160000_schedule_purge_audit.php tests/unit/SchedulePurgeAuditMigrationTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: migrazione che programma la pulizia notturna dell'audit e la prima esecuzione dopo il deploy" -- src/migrations/m261102_160000_schedule_purge_audit.php tests/unit/SchedulePurgeAuditMigrationTest.php
```

---

### Task 5: Servizio di retention dell'audit — intelligence

Repo `intelligence`, branch `fix-trigger-esclusioni`. Dipende dal Task 3.

**Files:**
- Create: `/var/www/html/vendor/sharkom/intelligence/src/services/AuditRetentionService.php`
- Test: `/var/www/html/vendor/sharkom/intelligence/tests/unit/AuditRetentionServiceTest.php`

**Step 0:** branch corrente = `fix-trigger-esclusioni`; `podman exec soundorgan-php test -f /var/www/html/vendor/sharkom/yii2-dev-helper/src/maintenance/TableRetention.php && echo ok` → `ok`.

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\intelligence\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\devhelper\maintenance\OptimizeResult;
use sharkom\intelligence\services\AuditRetentionService;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

/**
 * Retention di intelligence_db_audit su soundorgan_test, in una transazione annullata.
 * Le prove non fanno mai ALTER (sotto soglia): l'ALTER farebbe commit implicito.
 */
final class AuditRetentionServiceTest extends Unit
{
    private const ADESSO = '2026-11-02 03:00:00';

    private ?Transaction $transazione = null;
    private $logLevel;

    protected function _before(): void
    {
        $this->logLevel = Yii::$app->params['LogLevel'] ?? null;
        Yii::$app->params['LogLevel'] = 'error';
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->params['LogLevel'] = $this->logLevel;
    }

    private function riga(?string $data): int
    {
        Yii::$app->db->createCommand()->insert('intelligence_db_audit', [
            'audit_type' => 'data_change',
            'event_type' => 'afterUpdate',
            'table_name' => 'test_retention',
            'record_id' => 1,
            'changes' => '{}',
            'date' => $data,
            'provenience' => 'test_retention_audit',
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('intelligence_db_audit')->where(['id' => $id])->exists();
    }

    private function servizio(): AuditRetentionService
    {
        return new AuditRetentionService(Yii::$app->db);
    }

    public function testCancellaSoloLeRighePiuVecchieDellaRetention(): void
    {
        $vecchia = $this->riga('2026-09-01 10:00:00');
        $recente = $this->riga('2026-10-20 10:00:00');
        $senzaData = $this->riga(null);

        $report = $this->servizio()->run(30, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFalse($this->esiste($vecchia));
        $this->assertTrue($this->esiste($recente));
        $this->assertTrue($this->esiste($senzaData));
        $this->assertGreaterThanOrEqual(1, $report->purge->deleted);
        $this->assertSame('2026-10-03 03:00:00', $report->cutoff);
        $this->assertNull($report->optimize);
    }

    public function testConfineDellaSoglia(): void
    {
        $allaSoglia = $this->riga('2026-10-03 03:00:00');
        $unSecondoPrima = $this->riga('2026-10-03 02:59:59');

        $this->servizio()->run(30, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertTrue($this->esiste($allaSoglia));
        $this->assertFalse($this->esiste($unSecondoPrima));
    }

    public function testOttimizzazioneSottoSogliaSenzaAlter(): void
    {
        $this->riga('2026-09-01 10:00:00');

        $report = $this->servizio()->run(30, true, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(OptimizeResult::BELOW_THRESHOLD, $report->optimize->status);
        // Nessun commit implicito: la transazione della prova e' ancora aperta.
        $this->assertSame(1, (int) Yii::$app->db->createCommand('SELECT @@in_transaction')->queryScalar());
    }

    public function testGiorniNonValidi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servizio()->run(0, false, 60);
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/intelligence/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit AuditRetentionServiceTest
```
Expected: errore `Class "sharkom\intelligence\services\AuditRetentionService" not found`.

**Step 3: Implementazione minima**

```php
<?php

declare(strict_types=1);

namespace sharkom\intelligence\services;

use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\devhelper\LogHelper;
use sharkom\devhelper\maintenance\RetentionReport;
use sharkom\devhelper\maintenance\TableRetention;
use Yii;
use yii\db\Connection;

/**
 * Retention di intelligence_db_audit: cancella a blocchi le righe con `date` piu'
 * vecchia di N giorni (indice intelligence_db_audit_date_index) e, se richiesto,
 * ricostruisce la tabella online per restituire lo spazio.
 *
 * Le righe con `date` NULL non vengono toccate. I rollback dell'audit lavorano su una
 * riga per volta: cancellare le righe vecchie toglie solo la possibilita' di
 * ripristinare quegli eventi. DELETE diretta: niente audit della cancellazione.
 */
class AuditRetentionService
{
    public const TABLE = 'intelligence_db_audit';
    public const DEFAULT_DAYS = 30;
    public const LOG_CATEGORY = 'intelligence_retention';

    private TableRetention $retention;

    public function __construct(?Connection $db = null, ?TableRetention $retention = null)
    {
        $db = $db ?? Yii::$app->db;
        $this->retention = $retention ?? new TableRetention($db, self::LOG_CATEGORY);
    }

    public function run(int $days, bool $optimize, int $maxSeconds, ?DateTimeImmutable $now = null): RetentionReport
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Giorni di retention non validi: {$days}");
        }
        $cutoff = ($now ?? new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $report = $this->retention->run(self::TABLE, ['<', 'date', $cutoff], 'date', $cutoff, $maxSeconds, $optimize);

        LogHelper::log($report->isError() ? 'error' : 'info', $report->summary(), self::LOG_CATEGORY);
        return $report;
    }
}
```

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (4 tests, ...)`.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/intelligence/src/services /var/www/html/vendor/sharkom/intelligence/tests/unit/AuditRetentionServiceTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git add src/services/AuditRetentionService.php tests/unit/AuditRetentionServiceTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: retention di intelligence_db_audit a blocchi con ottimizzazione opzionale" -- src/services/AuditRetentionService.php tests/unit/AuditRetentionServiceTest.php
```

---

### Task 6: Comando intelligence/maintenance/purge-audit — intelligence

Repo `intelligence`, branch `fix-trigger-esclusioni`. Dipende dal Task 5. `Module.php` non si tocca: in console imposta già `controllerNamespace = 'sharkom\intelligence\commands'`.

**Files:**
- Create: `/var/www/html/vendor/sharkom/intelligence/src/commands/MaintenanceController.php`
- Test: `/var/www/html/vendor/sharkom/intelligence/tests/unit/MaintenanceControllerTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\intelligence\tests\unit;

use Codeception\Test\Unit;
use sharkom\intelligence\commands\MaintenanceController;
use Yii;
use yii\console\ExitCode;
use yii\db\Query;
use yii\db\Transaction;

final class MaintenanceControllerTest extends Unit
{
    private ?Transaction $transazione = null;
    private $logLevel;

    protected function _before(): void
    {
        $this->logLevel = Yii::$app->params['LogLevel'] ?? null;
        Yii::$app->params['LogLevel'] = 'error';
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->params['LogLevel'] = $this->logLevel;
    }

    public function testPurgeAuditCancellaERestituisceOk(): void
    {
        Yii::$app->db->createCommand()->insert('intelligence_db_audit', [
            'table_name' => 'test_retention',
            'date' => date('Y-m-d H:i:s', strtotime('-40 days')),
            'provenience' => 'test_retention_audit',
        ])->execute();
        $id = (int) Yii::$app->db->getLastInsertID();

        $comando = new MaintenanceController('maintenance', Yii::$app);
        $comando->days = 30;
        $comando->optimize = 0;

        $this->assertSame(ExitCode::OK, $comando->actionPurgeAudit());
        $this->assertFalse((new Query())->from('intelligence_db_audit')->where(['id' => $id])->exists());
    }

    public function testGiorniNonValidi(): void
    {
        $comando = new MaintenanceController('maintenance', Yii::$app);
        $comando->days = 0;

        $this->assertSame(ExitCode::USAGE, $comando->actionPurgeAudit());
    }

    public function testOpzioni(): void
    {
        $comando = new MaintenanceController('maintenance', Yii::$app);

        $this->assertContains('maxSeconds', $comando->options('purge-audit'));
        $this->assertContains('optimize', $comando->options('purge-audit'));
        $this->assertContains('days', $comando->options('purge-audit'));
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/intelligence/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit MaintenanceControllerTest
```
Expected: errore `Class "sharkom\intelligence\commands\MaintenanceController" not found`.

**Step 3: Implementazione minima**

```php
<?php

declare(strict_types=1);

namespace sharkom\intelligence\commands;

use sharkom\intelligence\services\AuditRetentionService;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Manutenzione delle tabelle di intelligence.
 *
 *   php yii intelligence/maintenance/purge-audit [--days=30] [--optimize=1] [--max-seconds=1200]
 *
 * Programmato dalla migrazione m261102_160000 (dispatcher alle 2:30 oppure cron_job).
 * Exit 1 solo se la ricostruzione fallisce per un errore diverso dal lock.
 */
class MaintenanceController extends Controller
{
    /** @var int giorni di audit da tenere */
    public $days = AuditRetentionService::DEFAULT_DAYS;
    /** @var int 1 = dopo la pulizia ricostruisce la tabella online, se conviene */
    public $optimize = 0;
    /** @var int tempo massimo della cancellazione, in secondi */
    public $maxSeconds = 1200;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['days', 'optimize', 'maxSeconds']);
    }

    public function actionPurgeAudit(): int
    {
        $days = (int) $this->days;
        if ($days < 1) {
            $this->stderr("--days deve essere almeno 1\n");
            return ExitCode::USAGE;
        }

        $report = (new AuditRetentionService())->run($days, (bool) (int) $this->optimize, (int) $this->maxSeconds);

        $this->stdout($report->summary() . "\n");
        return $report->isError() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
```

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (3 tests, ...)`.

**Step 5: La route è registrata in console (sola lettura, nessun accesso al DB)**

```bash
podman exec -u www-data -w /var/www/html soundorgan-php php yii help intelligence/maintenance/purge-audit
```
Expected: l'help elenca `--days`, `--optimize`, `--max-seconds`.

**Step 6: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/intelligence/src/commands /var/www/html/vendor/sharkom/intelligence/tests/unit/MaintenanceControllerTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git add src/commands/MaintenanceController.php tests/unit/MaintenanceControllerTest.php
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: comando intelligence/maintenance/purge-audit" -- src/commands/MaintenanceController.php tests/unit/MaintenanceControllerTest.php
```

---

### Task 7: Suite di test di yii2-cron sul DB di test — yii2-cron

Repo `yii2-cron`, branch `retention-pulizie`. Indipendente da dev-helper. La suite esiste (`tests/unit`, 12 test di CronScheduleHelper/CronScheduleStatus) ma il bootstrap carica solo l'autoload: senza applicazione né DB. Si estende sul modello di intelligence, con guardia sul nome del DB.

**Files:**
- Modify: `/var/www/html/vendor/sharkom/yii2-cron/tests/_bootstrap.php`
- Test: `/var/www/html/vendor/sharkom/yii2-cron/tests/unit/TestDbGuardTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\Module;
use Yii;

/**
 * La suite gira su un'applicazione console collegata al solo DB di test soundorgan_test.
 */
final class TestDbGuardTest extends Unit
{
    public function testApplicazioneSulDbDiTest(): void
    {
        $this->assertNotNull(Yii::$app);
        $this->assertSame('soundorgan_test', Yii::$app->db->createCommand('SELECT DATABASE()')->queryScalar());
        $this->assertInstanceOf(Module::class, Yii::$app->getModule('cron'));
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit TestDbGuardTest
```
Expected: FAIL/ERROR (`Yii::$app` null o classe `Yii` non caricata).

**Step 3: Implementazione minima** — sostituire tutto `tests/_bootstrap.php` con:

```php
<?php
defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/../../../../vendor/yiisoft/yii2/Yii.php';

// I test usano SEMPRE il DB di test isolato `soundorgan_test` definito in
// common/config/test-local.php, mai il DB vero: MYSQL_DATABASE (che nel container
// punta al DB dell'applicazione) viene volutamente ignorata.
// Lanciare sempre sotto il lock comune:
//   podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php \
//     flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit
$testLocal = require __DIR__ . '/../../../../common/config/test-local.php';
$dbConfig = $testLocal['components']['db'];
// Regex esatta come common/tests/_db_guard.php: `soundorgan_test_x` non passa.
if (!preg_match('/(^|;|:)dbname=soundorgan_test(;|$)/', (string)($dbConfig['dsn'] ?? ''))) {
    throw new RuntimeException('Bootstrap test yii2-cron: common/config/test-local.php non punta al DB di test soundorgan_test.');
}

$vendor = realpath(__DIR__ . '/../../../../vendor');
// Runtime fuori dal pacchetto e per utente (vedi tests/bootstrap.php di yii2-dev-helper).
$runtime = sys_get_temp_dir() . '/yii2-cron-tests-' . (function_exists('posix_geteuid') ? (string)posix_geteuid() : 'pid' . getmypid());
if (!is_dir($runtime)) {
    @mkdir($runtime, 0777, true);
}

new \yii\console\Application([
    'id' => 'yii2-cron-tests',
    'basePath' => __DIR__,
    'runtimePath' => $runtime,
    'vendorPath' => $vendor,
    'aliases' => [
        '@sharkom/cron' => dirname(__DIR__),
        '@vendor' => $vendor,
    ],
    'params' => [
        // LogHelper in console stampa a video: nei test solo gli errori.
        'LogLevel' => 'error',
    ],
    'modules' => [
        'cron' => ['class' => \sharkom\cron\Module::class],
    ],
    'components' => [
        'db' => $dbConfig,
    ],
]);
```

**Step 4: Eseguire tutta la suite (anche i test esistenti)**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit
```
Expected: `OK (13 tests, ...)`. Se un test esistente si rompe per la presenza dell'applicazione: sistemare il bootstrap, non il test.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown www-data:www-data /var/www/html/vendor/sharkom/yii2-cron/tests/_bootstrap.php /var/www/html/vendor/sharkom/yii2-cron/tests/unit/TestDbGuardTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git add tests/_bootstrap.php tests/unit/TestDbGuardTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "test: suite di yii2-cron sul DB di test soundorgan_test con guardia sul nome"
```

---

### Task 8: Migrazione che programma purge-spool — yii2-cron

Repo `yii2-cron`, branch `retention-pulizie`. Dipende dal Task 7, non da dev-helper.

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-cron/migrations/m261102_160100_schedule_purge_spool.php`
- Test: `/var/www/html/vendor/sharkom/yii2-cron/tests/unit/SchedulePurgeSpoolMigrationTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

require_once dirname(__DIR__, 2) . '/migrations/m261102_160100_schedule_purge_spool.php';

/**
 * Migrazione che programma cron/maintenance/purge-spool: tre rami, idempotenza e down.
 */
final class SchedulePurgeSpoolMigrationTest extends Unit
{
    private const MIGRAZIONE = \m261102_160100_schedule_purge_spool::class;

    private ?Transaction $transazione = null;

    protected function _before(): void
    {
        $this->transazione = Yii::$app->db->beginTransaction();
        ob_start();
    }

    protected function _after(): void
    {
        ob_end_clean();
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
    }

    private function migrazione(bool $conDispatcher = true, bool $conCron = true): \m261102_160100_schedule_purge_spool
    {
        return new class($conDispatcher, $conCron) extends \m261102_160100_schedule_purge_spool {
            private bool $conDispatcher;
            private bool $conCron;

            public function __construct(bool $conDispatcher, bool $conCron)
            {
                $this->conDispatcher = $conDispatcher;
                $this->conCron = $conCron;
                parent::__construct(['db' => Yii::$app->db]);
            }

            protected function jobDispatcherAvailable(): bool
            {
                return $this->conDispatcher && parent::jobDispatcherAvailable();
            }

            protected function cronJobAvailable(): bool
            {
                return $this->conCron && parent::cronJobAvailable();
            }
        };
    }

    private function job(): ?array
    {
        $job = (new Query())->from('system_jobs')->where(['name' => (self::MIGRAZIONE)::JOB_NAME])->one();
        return $job === false ? null : $job;
    }

    private function definizioni(int $jobId): array
    {
        return (new Query())->from('schedule_definitions')->where(['system_job_id' => $jobId])->indexBy('rrule')->all();
    }

    private function cronJob(): array
    {
        return (new Query())->from('cron_job')->where(['like', 'command', (self::MIGRAZIONE)::ROUTE])->all();
    }

    public function testConDispatcherCreaJobNotturnoEdEsecuzioneUnicaMaterializzata(): void
    {
        $prima = time();

        $this->migrazione()->up();

        $job = $this->job();
        $this->assertNotNull($job);
        $this->assertSame(
            ['route' => 'cron/maintenance/purge-spool', 'args' => ['--optimize=1']],
            json_decode($job['params'], true)
        );
        $this->assertSame(3600, (int) $job['catch_up_threshold_seconds']);
        $this->assertSame(1800, (int) $job['timeout']);

        $definizioni = $this->definizioni((int) $job['id']);
        $this->assertEqualsCanonicalizing(
            ['FREQ=DAILY;BYHOUR=2;BYMINUTE=45;BYSECOND=0', 'FREQ=DAILY;COUNT=1'],
            array_keys($definizioni)
        );
        $unica = $definizioni['FREQ=DAILY;COUNT=1'];
        $dtstart = (new DateTimeImmutable($unica['dtstart'], new DateTimeZone('Europe/Rome')))->getTimestamp();
        $this->assertGreaterThanOrEqual($prima + 115, $dtstart);
        $this->assertLessThanOrEqual(time() + 125, $dtstart);

        $eventi = (new Query())->from('scheduled_events')->where(['schedule_definition_id' => $unica['id']])->all();
        $this->assertCount(1, $eventi);
        $this->assertSame($unica['dtstart'], $eventi[0]['run_at']);
        $this->assertSame([], $this->cronJob());
    }

    public function testIdempotenteEDown(): void
    {
        $this->migrazione()->up();
        $this->migrazione()->up();
        $job = $this->job();
        $this->assertCount(2, $this->definizioni((int) $job['id']));
        $this->assertSame(1, (int) (new Query())->from('scheduled_events')->where(['system_job_id' => $job['id']])->count());

        $this->migrazione()->down();

        $this->assertNull($this->job());
        $this->assertSame([], $this->definizioni((int) $job['id']));
    }

    public function testSenzaDispatcherCreaIlCronJobGiornaliero(): void
    {
        $this->migrazione(false, true)->up();
        $this->migrazione(false, true)->up();

        $righe = $this->cronJob();
        $this->assertCount(1, $righe);
        $this->assertSame('45 2 * * *', $righe[0]['schedule']);
        $this->assertStringStartsWith(PHP_BINARY . ' ', $righe[0]['command']);
        $this->assertStringEndsWith('yii cron/maintenance/purge-spool --optimize=1', $righe[0]['command']);

        $this->migrazione(false, true)->down();
        $this->assertSame([], $this->cronJob());
    }

    public function testSenzaDispatcherNeCronNessunaAzione(): void
    {
        $this->assertTrue($this->migrazione(false, false)->up());
        $this->assertNull($this->job());
        $this->assertSame([], $this->cronJob());
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit SchedulePurgeSpoolMigrationTest
```
Expected: errore `Failed opening required '.../migrations/m261102_160100_schedule_purge_spool.php'`.

**Step 3: Implementazione minima**

Copiare la migrazione del Task 4 e cambiare **solo** l'intestazione, il nome della classe e le costanti:

```bash
podman exec soundorgan-php bash -c 'mkdir -p /var/www/html/vendor/sharkom/yii2-cron/migrations && cp /var/www/html/vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php /var/www/html/vendor/sharkom/yii2-cron/migrations/m261102_160100_schedule_purge_spool.php'
```

Se il Task 4 non è ancora stato fatto, scrivere il file per intero dal codice del Task 4, poi applicare le stesse sostituzioni. Il file risultante deve contenere esattamente queste differenze rispetto a quello del Task 4:

```php
/**
 * Programma la pulizia di commands_spool (retention 14 giorni, o purge_spool_days del
 * modulo cron) con il comando `cron/maintenance/purge-spool --optimize=1`.
 *
 * Tre rami:
 *  - job dispatcher installato (SoundOrgan): system job "Manutenzione: pulizia
 *    commands_spool" (console.run) con due schedule: ogni notte alle 2:45 e
 *    un'esecuzione unica (COUNT=1) due minuti dopo la migrazione, per la prima pulizia
 *    con ottimizzazione subito dopo il deploy ma fuori dal tempo delle migrazioni.
 *    L'esecuzione unica viene materializzata subito: il materializzatore notturno
 *    (2:00) non crea occorrenze nel passato e la perderebbe;
 *  - niente dispatcher ma tabella cron_job di yii2-cron (NormaFlow): cron_job
 *    giornaliero "45 2 * * *";
 *  - nessuno dei due: nessuna azione.
 *
 * (resto del docblock identico al Task 4)
 */
class m261102_160100_schedule_purge_spool extends Migration
{
    public const JOB_NAME = 'Manutenzione: pulizia commands_spool';
    public const ROUTE = 'cron/maintenance/purge-spool';
    public const ARGS = ['--optimize=1'];
    public const RRULE_NOTTE = 'FREQ=DAILY;BYHOUR=2;BYMINUTE=45;BYSECOND=0';
    public const RRULE_UNICA = 'FREQ=DAILY;COUNT=1';
    public const CRON_SCHEDULE = '45 2 * * *';
    public const RITARDO_PRIMA_ESECUZIONE = 120;

    private const TYPE_CODE = 'console.run';
    private const TIMEZONE = 'Europe/Rome';
    private const DESCRIZIONE = 'Cancella a blocchi le righe concluse di commands_spool piu\' vecchie della retention '
        . '(14 giorni) e i loro file di log non piu\' usati e, se conviene, ricostruisce la tabella online.';

    // ... metodi identici al Task 4 (up, down, scheduleWithDispatcher, insertDefinition,
    //     materialize, scheduleWithCronJob, yiiScript, findJobId, hasDefinition,
    //     jobDispatcherAvailable, cronJobAvailable)
}
```

Verificare che non ci siano altre differenze:

```bash
podman exec soundorgan-php bash -c 'diff /var/www/html/vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php /var/www/html/vendor/sharkom/yii2-cron/migrations/m261102_160100_schedule_purge_spool.php'
```
Expected: solo righe del docblock, `class`, `JOB_NAME`, `ROUTE`, `RRULE_NOTTE`, `CRON_SCHEDULE`, `DESCRIZIONE`.

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (4 tests, ...)`.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/yii2-cron/migrations /var/www/html/vendor/sharkom/yii2-cron/tests/unit/SchedulePurgeSpoolMigrationTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git add migrations/m261102_160100_schedule_purge_spool.php tests/unit/SchedulePurgeSpoolMigrationTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: migrazione che programma la pulizia notturna di commands_spool e la prima esecuzione dopo il deploy"
```

---

### Task 9: Servizio di retention di commands_spool — yii2-cron

Repo `yii2-cron`, branch `retention-pulizie`. Dipende dai Task 3 e 7.

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-cron/services/SpoolRetentionService.php`
- Test: `/var/www/html/vendor/sharkom/yii2-cron/tests/unit/SpoolRetentionServiceTest.php`

**Step 1: Scrivere il test che fallisce**

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\cron\services\SpoolRetentionService;
use sharkom\devhelper\maintenance\OptimizeResult;
use Yii;
use yii\db\Query;
use yii\db\Transaction;
use yii\helpers\FileHelper;

/**
 * Retention di commands_spool su soundorgan_test, in una transazione annullata.
 * Cartella dei log e cartella "fuori" sono temporanee e create dalla prova.
 */
final class SpoolRetentionServiceTest extends Unit
{
    private const ADESSO = '2026-11-02 03:00:00';
    private const VECCHIA = '2026-10-01 10:00:00';
    private const RECENTE = '2026-10-30 10:00:00';

    private ?Transaction $transazione = null;
    private string $base;
    private string $cartellaLog;
    private string $cartellaFuori;

    protected function _before(): void
    {
        $this->base = sys_get_temp_dir() . '/spool-retention-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->cartellaLog = $this->base . '/logs';
        $this->cartellaFuori = $this->base . '/fuori';
        mkdir($this->cartellaLog, 0777, true);
        mkdir($this->cartellaFuori, 0777, true);
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        FileHelper::removeDirectory($this->base);
    }

    /** @param int|null $completed 0 pendente, 1 concluso, -1 errore, null */
    private function riga(?string $creata, ?int $completed, ?string $file = null): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'provenience_id' => 0,
            'logs_file' => $file,
            'created_at' => $creata,
            'completed' => $completed,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function file(string $cartella, string $nome): string
    {
        $percorso = $cartella . '/' . $nome;
        file_put_contents($percorso, "log\n");
        return $percorso;
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    private function servizio(): SpoolRetentionService
    {
        return new SpoolRetentionService(Yii::$app->db, null, $this->cartellaLog);
    }

    public function testCancellaSoloRigheConcluseOltreLaRetention(): void
    {
        $conclusa = $this->riga(self::VECCHIA, 1);
        $inErrore = $this->riga(self::VECCHIA, -1);
        $pendente = $this->riga(self::VECCHIA, 0);
        $completedNull = $this->riga(self::VECCHIA, null);
        $senzaData = $this->riga(null, 1);
        $senzaDataPendente = $this->riga(null, 0);
        $recente = $this->riga(self::RECENTE, 1);

        $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFalse($this->esiste($conclusa));
        $this->assertFalse($this->esiste($inErrore));
        $this->assertTrue($this->esiste($pendente), 'mai le righe pendenti o in esecuzione');
        $this->assertTrue($this->esiste($completedNull));
        $this->assertFalse($this->esiste($senzaData));
        $this->assertTrue($this->esiste($senzaDataPendente));
        $this->assertTrue($this->esiste($recente));
    }

    public function testRimuoveSoloFileDentroLaCartellaENonPiuUsati(): void
    {
        $solo = $this->file($this->cartellaLog, 'solo.log');
        $condiviso = $this->file($this->cartellaLog, 'condiviso.log');
        $diPendente = $this->file($this->cartellaLog, 'pendente.log');
        $fuori = $this->file($this->cartellaFuori, 'fuori.log');
        $risalita = $this->file($this->cartellaFuori, 'risalita.log');
        $bersaglioLink = $this->file($this->cartellaFuori, 'bersaglio.log');
        $link = $this->cartellaLog . '/link.log';
        symlink($bersaglioLink, $link);

        $this->riga(self::VECCHIA, 1, $solo);
        $this->riga(self::VECCHIA, 1, $condiviso);
        $this->riga(self::RECENTE, 1, $condiviso);
        $this->riga(self::VECCHIA, 0, $diPendente);
        $this->riga(self::VECCHIA, 1, $fuori);
        $this->riga(self::VECCHIA, 1, $this->cartellaLog . '/../fuori/risalita.log');
        $this->riga(self::VECCHIA, 1, $link);

        $report = $this->servizio()->run(14, false, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertFileDoesNotExist($solo);
        $this->assertFileExists($condiviso, 'usato ancora da una riga recente');
        $this->assertFileExists($diPendente, 'la riga pendente resta');
        $this->assertFileExists($fuori, 'fuori dalla cartella dei log');
        $this->assertFileExists($risalita, 'percorso con .. verso fuori');
        $this->assertFileExists($bersaglioLink, 'symlink verso fuori');
        $this->assertTrue(is_link($link));
        $this->assertSame(1, $report->filesRemoved);
    }

    public function testGiorniDaiParametriDelModulo(): void
    {
        $this->assertSame([14, null], SpoolRetentionService::resolveDays([]));
        $this->assertSame([7, null], SpoolRetentionService::resolveDays(['purge_spool_days' => 7]));
        $this->assertSame([7, null], SpoolRetentionService::resolveDays(['purge_spool_days' => 7, 'purge_log_interval' => 3]));

        [$giorni, $avviso] = SpoolRetentionService::resolveDays(['purge_log_interval' => 3]);
        $this->assertSame(90, $giorni);
        $this->assertStringContainsString('purge_log_interval', $avviso);
        $this->assertStringContainsString('purge_spool_days', $avviso);
    }

    public function testOttimizzazioneSottoSogliaSenzaAlter(): void
    {
        $this->riga(self::VECCHIA, 1);

        $report = $this->servizio()->run(14, true, 60, new DateTimeImmutable(self::ADESSO));

        $this->assertSame(OptimizeResult::BELOW_THRESHOLD, $report->optimize->status);
        $this->assertSame(1, (int) Yii::$app->db->createCommand('SELECT @@in_transaction')->queryScalar());
    }

    public function testGiorniNonValidi(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->servizio()->run(0, false, 60);
    }
}
```

**Step 2: Eseguire il test e vederlo fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit SpoolRetentionServiceTest
```
Expected: errore `Class "sharkom\cron\services\SpoolRetentionService" not found`.

**Step 3: Implementazione minima**

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\services;

use DateTimeImmutable;
use InvalidArgumentException;
use sharkom\devhelper\LogHelper;
use sharkom\devhelper\maintenance\RetentionReport;
use sharkom\devhelper\maintenance\TableRetention;
use Yii;
use yii\db\Connection;
use yii\db\Query;

/**
 * Retention di commands_spool: cancella a blocchi le righe concluse (completed <> 0,
 * quindi anche -1) create prima della soglia o senza created_at; mai le righe
 * pendenti o in esecuzione (completed = 0) ne' quelle con completed NULL.
 *
 * File di log: logs_file e' condiviso tra le esecuzioni dello stesso job
 * (es. command_cron_job_22.log, /var/log/offers2mandates.log). Un file si rimuove solo
 * se sta dentro la cartella dei log attesa (@runtime/logs, la stessa dello spooler,
 * confronto sui realpath: niente `..` ne' symlink verso fuori) e se nessuna riga
 * rimasta in tabella lo usa ancora.
 */
class SpoolRetentionService
{
    public const TABLE = 'commands_spool';
    public const DEFAULT_DAYS = 14;
    public const LOG_CATEGORY = 'cron_retention';

    private Connection $db;
    private TableRetention $retention;
    private string $logDir;

    public function __construct(?Connection $db = null, ?TableRetention $retention = null, ?string $logDir = null)
    {
        $this->db = $db ?? Yii::$app->db;
        $this->retention = $retention ?? new TableRetention($this->db, self::LOG_CATEGORY);
        $this->logDir = $logDir ?? Yii::getAlias('@runtime/logs');
    }

    /**
     * Giorni di retention dai parametri del modulo cron: purge_spool_days (giorni) oppure,
     * deprecato, purge_log_interval (mesi, convertito in mesi x 30).
     *
     * @return array{0:int, 1:?string} giorni e avviso di deprecazione
     */
    public static function resolveDays(array $params): array
    {
        if (isset($params['purge_spool_days'])) {
            return [(int) $params['purge_spool_days'], null];
        }
        if (isset($params['purge_log_interval'])) {
            $days = (int) $params['purge_log_interval'] * 30;
            return [$days, "Parametro purge_log_interval deprecato: usare purge_spool_days (convertito in {$days} giorni)"];
        }
        return [self::DEFAULT_DAYS, null];
    }

    public function run(int $days, bool $optimize, int $maxSeconds, ?DateTimeImmutable $now = null): RetentionReport
    {
        if ($days < 1) {
            throw new InvalidArgumentException("Giorni di retention non validi: {$days}");
        }
        $cutoff = ($now ?? new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $candidates = [];
        $report = $this->retention->run(
            self::TABLE,
            ['and', ['<>', 'completed', 0], ['or', ['<', 'created_at', $cutoff], ['created_at' => null]]],
            'created_at',
            $cutoff,
            $maxSeconds,
            $optimize,
            ['logs_file'],
            function (array $rows) use (&$candidates): void {
                foreach ($rows as $row) {
                    $file = (string) ($row['logs_file'] ?? '');
                    if ($file !== '' && $this->isInsideLogDir($file)) {
                        $candidates[$file] = true;
                    }
                }
            }
        );
        $report->filesRemoved = $this->removeUnreferencedFiles(array_keys($candidates));

        LogHelper::log($report->isError() ? 'error' : 'info', $report->summary(), self::LOG_CATEGORY);
        return $report;
    }

    /** True se $path e' un file regolare dentro la cartella dei log (realpath). */
    public function isInsideLogDir(string $path): bool
    {
        $dir = realpath($this->logDir);
        $real = realpath($path);
        if ($dir === false || $real === false || !is_file($real)) {
            return false;
        }
        $prefix = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($real, $prefix, strlen($prefix)) === 0;
    }

    /**
     * @param string[] $files percorsi come scritti in logs_file
     */
    private function removeUnreferencedFiles(array $files): int
    {
        $removed = 0;
        foreach (array_chunk($files, 500) as $chunk) {
            $stillUsed = (new Query())
                ->select('logs_file')->distinct()
                ->from(self::TABLE)
                ->where(['logs_file' => $chunk])
                ->column($this->db);
            foreach (array_diff($chunk, $stillUsed) as $file) {
                // Ricontrollo al momento della rimozione e cancello il percorso risolto.
                $real = realpath($file);
                if ($real !== false && $this->isInsideLogDir($real) && @unlink($real)) {
                    $removed++;
                } else {
                    LogHelper::log('warning', "commands_spool: file di log non rimosso: {$file}", self::LOG_CATEGORY);
                }
            }
        }
        return $removed;
    }
}
```

**Step 4: Eseguire il test e vederlo passare**

Stesso comando dello Step 2. Expected: `OK (5 tests, ...)`.

**Step 5: Commit**

```bash
podman exec soundorgan-php chown -R www-data:www-data /var/www/html/vendor/sharkom/yii2-cron/services /var/www/html/vendor/sharkom/yii2-cron/tests/unit/SpoolRetentionServiceTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git add services/SpoolRetentionService.php tests/unit/SpoolRetentionServiceTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: retention di commands_spool senza righe pendenti e con file di log confinati"
```

---

### Task 10: Comando cron/maintenance/purge-spool e rimozione di purgeLogs — yii2-cron

Repo `yii2-cron`, branch `retention-pulizie`. Dipende dal Task 9.

**Files:**
- Create: `/var/www/html/vendor/sharkom/yii2-cron/commands/MaintenanceController.php`
- Modify: `/var/www/html/vendor/sharkom/yii2-cron/commands/CronController.php:64-91,160-172`
- Modify: `/var/www/html/vendor/sharkom/yii2-cron/commands/SpoolerController.php:618-623` (solo docblock)
- Modify: `/var/www/html/vendor/sharkom/yii2-cron/README.md:14-17`
- Test: `/var/www/html/vendor/sharkom/yii2-cron/tests/unit/MaintenanceControllerTest.php`
- Test: `/var/www/html/vendor/sharkom/yii2-cron/tests/unit/CronControllerRunTest.php`

**Step 1: Scrivere i test che falliscono**

`tests/unit/MaintenanceControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\commands\MaintenanceController;
use Yii;
use yii\console\ExitCode;
use yii\db\Query;
use yii\db\Transaction;

final class MaintenanceControllerTest extends Unit
{
    private ?Transaction $transazione = null;
    private array $parametri = [];

    protected function _before(): void
    {
        $this->parametri = Yii::$app->getModule('cron')->params;
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
        Yii::$app->getModule('cron')->params = $this->parametri;
    }

    private function riga(int $giorniFa): int
    {
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => date('Y-m-d H:i:s', strtotime("-{$giorniFa} days")),
            'completed' => 1,
        ])->execute();
        return (int) Yii::$app->db->getLastInsertID();
    }

    private function esiste(int $id): bool
    {
        return (new Query())->from('commands_spool')->where(['id' => $id])->exists();
    }

    public function testSenzaDaysUsaPurgeSpoolDaysDelModulo(): void
    {
        $modulo = Yii::$app->getModule('cron');
        $modulo->params = ['purge_spool_days' => 30];
        $vecchia = $this->riga(40);
        $dentro = $this->riga(20);

        $comando = new MaintenanceController('maintenance', $modulo);

        $this->assertSame(ExitCode::OK, $comando->actionPurgeSpool());
        $this->assertFalse($this->esiste($vecchia));
        $this->assertTrue($this->esiste($dentro));
    }

    public function testDaysEsplicitoVinceSuiParametri(): void
    {
        $modulo = Yii::$app->getModule('cron');
        $modulo->params = ['purge_spool_days' => 30];
        $riga = $this->riga(20);

        $comando = new MaintenanceController('maintenance', $modulo);
        $comando->days = 14;

        $this->assertSame(ExitCode::OK, $comando->actionPurgeSpool());
        $this->assertFalse($this->esiste($riga));
    }

    public function testGiorniNonValidi(): void
    {
        $comando = new MaintenanceController('maintenance', Yii::$app->getModule('cron'));
        $comando->days = 0;

        $this->assertSame(ExitCode::USAGE, $comando->actionPurgeSpool());
    }
}
```

`tests/unit/CronControllerRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace sharkom\cron\tests\unit;

use Codeception\Test\Unit;
use sharkom\cron\commands\CronController;
use Yii;
use yii\db\Query;
use yii\db\Transaction;

/**
 * cron/cron/run non pulisce piu' commands_spool: la pulizia e' cron/maintenance/purge-spool.
 */
final class CronControllerRunTest extends Unit
{
    private ?Transaction $transazione = null;

    protected function _before(): void
    {
        $this->transazione = Yii::$app->db->beginTransaction();
    }

    protected function _after(): void
    {
        if ($this->transazione !== null && $this->transazione->isActive) {
            $this->transazione->rollBack();
        }
    }

    public function testRunNonCancellaPiuLeRigheDiCommandsSpool(): void
    {
        $this->assertFalse(method_exists(CronController::class, 'purgeLogs'));

        // Nessun job dovuto: il run non accoda nulla.
        Yii::$app->db->createCommand()->update('cron_job', ['active' => 0])->execute();
        Yii::$app->db->createCommand()->insert('commands_spool', [
            'command' => 'echo test',
            'provenience' => 'test_retention',
            'created_at' => '2020-01-01 00:00:00',
            'completed' => 1,
        ])->execute();
        $id = (int) Yii::$app->db->getLastInsertID();

        (new CronController('cron', Yii::$app->getModule('cron')))->actionRun();

        $this->assertTrue((new Query())->from('commands_spool')->where(['id' => $id])->exists());
    }
}
```

**Step 2: Eseguire i test e vederli fallire**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit MaintenanceControllerTest
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit CronControllerRunTest
```
Expected: il primo con `Class "sharkom\cron\commands\MaintenanceController" not found`; il secondo FAIL su `method_exists` (purgeLogs esiste ancora).

**Step 3: Implementazione minima**

`commands/MaintenanceController.php`:

```php
<?php

namespace sharkom\cron\commands;

use sharkom\cron\services\SpoolRetentionService;
use sharkom\devhelper\LogHelper;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Manutenzione della coda comandi.
 *
 *   php yii cron/maintenance/purge-spool [--days=14] [--optimize=1] [--max-seconds=1200]
 *
 * Senza --days valgono i parametri del modulo cron: purge_spool_days (giorni, default 14)
 * oppure, deprecato, purge_log_interval (mesi, convertito in mesi x 30 con un avviso).
 * Programmato dalla migrazione m261102_160100 (dispatcher alle 2:45 oppure cron_job).
 * Alternativa manuale piu' semplice (senza file e senza ottimizzazione): cron/spooler/cleanup.
 */
class MaintenanceController extends Controller
{
    /** @var int|null giorni da tenere; null = dai parametri del modulo */
    public $days;
    /** @var int 1 = dopo la pulizia ricostruisce la tabella online, se conviene */
    public $optimize = 0;
    /** @var int tempo massimo della cancellazione, in secondi */
    public $maxSeconds = 1200;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['days', 'optimize', 'maxSeconds']);
    }

    public function actionPurgeSpool(): int
    {
        if ($this->days !== null && $this->days !== '') {
            $days = (int) $this->days;
        } else {
            [$days, $warning] = SpoolRetentionService::resolveDays($this->module->params);
            if ($warning !== null) {
                LogHelper::log('warning', $warning, SpoolRetentionService::LOG_CATEGORY);
            }
        }
        if ($days < 1) {
            $this->stderr("--days deve essere almeno 1\n");
            return ExitCode::USAGE;
        }

        $report = (new SpoolRetentionService())->run($days, (bool) (int) $this->optimize, (int) $this->maxSeconds);

        $this->stdout($report->summary() . "\n");
        return $report->isError() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
```

`commands/CronController.php`. In `actionRun()` sostituire:

```php
        //$this->unlock();
        $this->purgeLogs();

        foreach (CronJob::findRunnable() as $job) {
```
con:
```php
        //$this->unlock();
        // La pulizia di commands_spool non e' piu' qui (era una DELETE senza LIMIT ogni
        // minuto che cancellava anche le righe pendenti): vedi cron/maintenance/purge-spool.

        foreach (CronJob::findRunnable() as $job) {
```
togliere la riga commentata `        //$this->purgeLogs();` a fine `actionRun()` (riga ~90) e cancellare per intero il metodo `private function purgeLogs()` (righe 160-172, incluso il commento vuoto che lo precede, se c'è).

`commands/SpoolerController.php`, docblock di `actionCleanup` (riga ~618), sostituire:
```php
    /**
     * Clean up old completed commands
     * @param int $days Number of days to keep completed commands
     * @return int Exit code
     */
```
con:
```php
    /**
     * Pulizia manuale delle righe concluse (completed = 1) piu' vecchie di N giorni.
     * Non schedulata: la pulizia automatica e' cron/maintenance/purge-spool (a blocchi,
     * con i file di log e l'ottimizzazione della tabella). Resta come alternativa manuale.
     * @param int $days giorni da tenere
     * @return int Exit code
     */
```

`README.md`, dopo il blocco "ChangeLog 5/May/2023" (prima di "Installation"), aggiungere:

```markdown
ChangeLog 2/Nov/2026
1. Removed the purge of commands_spool from every cron/cron/run (purge_log_interval)
2. New command: php yii cron/maintenance/purge-spool [--days=14] [--optimize=1] [--max-seconds=1200]
   - deletes in blocks of 5,000 only finished rows (completed <> 0) older than the retention
   - removes the command log files only inside @runtime/logs and only when no remaining row uses them
   - with --optimize=1 rebuilds the table online (ALGORITHM=INPLACE, LOCK=NONE) above the thresholds
3. Module param purge_spool_days (days, default 14) replaces purge_log_interval (months, converted x 30 with a deprecation warning)
4. Migration migrations/m261102_160100_schedule_purge_spool.php: nightly job (job dispatcher) or cron_job "45 2 * * *"
```

e nel blocco "ChangeLog 27/Feb/2023" sostituire la riga
`2. Add auto purge logs (use module param purge_log_interval | default 3 months)` con
`2. Add auto purge logs (use module param purge_log_interval | default 3 months) - removed on 2/Nov/2026, see below`.

**Step 4: Eseguire i test e vederli passare**

Stessi comandi dello Step 2. Expected: `OK (3 tests, ...)` e `OK (1 test, ...)`. Poi:

```bash
podman exec -u www-data -w /var/www/html soundorgan-php php yii help cron/maintenance/purge-spool
podman exec soundorgan-php grep -n "purgeLogs\|purge_log_interval" /var/www/html/vendor/sharkom/yii2-cron/commands/CronController.php
```
Expected: l'help elenca `--days`, `--optimize`, `--max-seconds`; il grep non trova nulla (exit 1).

**Step 5: Commit**

```bash
podman exec soundorgan-php chown www-data:www-data /var/www/html/vendor/sharkom/yii2-cron/commands/MaintenanceController.php /var/www/html/vendor/sharkom/yii2-cron/commands/CronController.php /var/www/html/vendor/sharkom/yii2-cron/commands/SpoolerController.php /var/www/html/vendor/sharkom/yii2-cron/README.md /var/www/html/vendor/sharkom/yii2-cron/tests/unit/MaintenanceControllerTest.php /var/www/html/vendor/sharkom/yii2-cron/tests/unit/CronControllerRunTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git add commands/MaintenanceController.php commands/CronController.php commands/SpoolerController.php README.md tests/unit/MaintenanceControllerTest.php tests/unit/CronControllerRunTest.php
podman exec -w /var/www/html/vendor/sharkom/yii2-cron soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: comando cron/maintenance/purge-spool, purge_spool_days e via la pulizia a ogni cron/cron/run"
```

---

### Task 11: Migrazioni copiate nel core

Repo core `/var/www/html`, branch `dev-adminlte_v2-2.2.6`. Dipende dai Task 4 e 8.

**Files:**
- Create: `/var/www/html/console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php`
- Create: `/var/www/html/console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php`

**Step 0: Branch**

```bash
podman exec -w /var/www/html soundorgan-php bash -c 'git status --short | head; git branch --show-current'
```
Se è su `adminlte_v2` con stato pulito: `podman exec -w /var/www/html soundorgan-php git switch dev-adminlte_v2-2.2.6`. Se ci sono modifiche non proprie: fermarsi e chiedere.

**Step 1: Verifica che fallisce (le copie non esistono)**

```bash
podman exec -w /var/www/html soundorgan-php ls console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
```
Expected: `No such file or directory` per entrambi.

**Step 2: Copia con rinomina della classe**

```bash
podman exec -w /var/www/html soundorgan-php bash -c '
cp vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php
sed -i "s/^class m261102_160000_schedule_purge_audit extends/class m261102_160000_v2_2_5_schedule_purge_audit extends/" console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php
cp vendor/sharkom/yii2-cron/migrations/m261102_160100_schedule_purge_spool.php console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
sed -i "s/^class m261102_160100_schedule_purge_spool extends/class m261102_160100_v2_2_5_schedule_purge_spool extends/" console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
chown www-data:www-data console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
php -l console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php
php -l console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
diff vendor/sharkom/intelligence/src/migrations/m261102_160000_schedule_purge_audit.php console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php
diff vendor/sharkom/yii2-cron/migrations/m261102_160100_schedule_purge_spool.php console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php'
```
Expected: `No syntax errors detected` due volte; ogni `diff` mostra solo la riga `class ...`.

**Step 3: Applicazione sul DB di test (mai sul DB dev)**

```bash
podman exec -w /var/www/html soundorgan-php flock /tmp/soundorgan_test.lock php yii migrate --db=db_test --interactive=0
```
Expected: per entrambe le migrazioni `+ job '...' creato`, due `+ schedule ...` e `> connessione diversa da Yii::$app->db: esecuzione unica non materializzata, lanciare a mano ...` (giusto: con `--db=db_test` il Materializer scriverebbe sul DB dell'applicazione). Eventuali altre migrazioni in attesa vengono applicate anch'esse: è la procedura di `docs/DbMigrationUsage.md`.

```bash
podman exec soundorgan-php bash -c 'export MYSQL_PWD=$MARIADB_SO_PASS; mysql -h soundorgan-mariadb -u$MARIADB_SO_USER soundorgan_test -e "SELECT j.name, d.rrule FROM system_jobs j JOIN schedule_definitions d ON d.system_job_id = j.id WHERE j.name LIKE \"Manutenzione: pulizia%\" ORDER BY j.name, d.rrule"'
```
Expected: 4 righe (due job × `FREQ=DAILY;BYHOUR=2;BYMINUTE=30|45;BYSECOND=0` e `FREQ=DAILY;COUNT=1`).

**Step 4: Down e di nuovo up sul DB di test**

```bash
podman exec -w /var/www/html soundorgan-php flock /tmp/soundorgan_test.lock php yii migrate/history 2 --db=db_test
```
Expected: le ultime due sono `m261102_160100_v2_2_5_schedule_purge_spool` e `m261102_160000_v2_2_5_schedule_purge_audit`. Solo in questo caso:

```bash
podman exec -w /var/www/html soundorgan-php flock /tmp/soundorgan_test.lock php yii migrate/down 2 --db=db_test --interactive=0
podman exec -w /var/www/html soundorgan-php flock /tmp/soundorgan_test.lock php yii migrate --db=db_test --interactive=0
```
Expected: down senza errori (la query di controllo dello Step 3 restituisce 0 righe dopo il down); up di nuovo con gli stessi messaggi.

**Step 5: Commit**

```bash
podman exec -w /var/www/html soundorgan-php git add console/migrations/m261102_160000_v2_2_5_schedule_purge_audit.php console/migrations/m261102_160100_v2_2_5_schedule_purge_spool.php
podman exec -w /var/www/html soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "feat: migrazioni v2.2.5 che programmano la pulizia di intelligence_db_audit e commands_spool"
```

---

### Task 12: CHANGELOG-v2.2.5 del core

Repo core, branch `dev-adminlte_v2-2.2.6`. Dipende da tutti i task precedenti.

**Files:**
- Modify: `/var/www/html/CHANGELOG-v2.2.5.txt` (nuova sezione prima di `[CORE]`, una riga in `[CORE]`, righe in `[DOPO IL DEPLOY]`)

**Step 1: Verifica che fallisce**

```bash
podman exec soundorgan-php grep -c "MANUTENZIONE DB" /var/www/html/CHANGELOG-v2.2.5.txt
```
Expected: `0`.

**Step 2: Nuova sezione**, subito prima della riga `[CORE]`:

```
[MANUTENZIONE DB] Retention di intelligence_db_audit e commands_spool (sharkom/intelligence, sharkom/yii2-cron, sharkom/yii2-dev-helper)

Il DB di ogni istanza occupa 13-15 GB: circa 7 GB sono intelligence_db_audit
(mai pulita) e 1,9 GB commands_spool (pulita a 3 mesi a ogni cron/cron/run,
con una DELETE senza LIMIT ogni minuto che cancellava anche le righe
pendenti e lasciava i file di log). Ora:
- php yii intelligence/maintenance/purge-audit [--days=30] [--optimize=1]
  [--max-seconds=1200]: tiene 30 giorni di audit. Gli eventi piu' vecchi non
  si possono piu' annullare con il rollback dell'audit.
- php yii cron/maintenance/purge-spool [--days=14] [--optimize=1]
  [--max-seconds=1200]: tiene 14 giorni di coda comandi e non tocca mai le
  righe pendenti o in esecuzione (completed=0). Un file di log dei comandi
  viene rimosso solo se sta in console/runtime/logs e nessuna riga rimasta lo
  usa (i file sono condivisi tra le esecuzioni dello stesso job; quelli in
  /var/log non vengono mai toccati).
- Cancellazione a blocchi di 5.000 righe con una pausa breve, al massimo 20
  minuti per esecuzione (il resto la notte dopo). DELETE diretta: nessun
  audit e nessun trigger.
- Recupero dello spazio: ricostruzione online della tabella (ALTER TABLE ...
  FORCE, ALGORITHM=INPLACE, LOCK=NONE, lock_wait_timeout 10 s), solo se lo
  spazio liberato supera 1 GB e il 30% della tabella e se c'e' spazio su
  disco. Se il blocco non arriva entro 10 s rinuncia con un warning e riprova
  la notte dopo. Lo spazio del datadir non e' leggibile dal container PHP:
  senza il parametro dbDiskProbePath si ricostruisce solo se i dati residui
  sono sotto 2 GB.
- yii2-cron: tolta la pulizia a ogni cron/cron/run. Il parametro di modulo
  purge_log_interval (mesi) e' sostituito da purge_spool_days (giorni,
  default 14); se c'e' solo il vecchio viene convertito (mesi x 30) con un
  warning. cron/spooler/cleanup resta come alternativa manuale.
- Programmazione (migrazioni): job del dispatcher "Manutenzione: pulizia
  audit intelligence" alle 2:30 e "Manutenzione: pulizia commands_spool"
  alle 2:45, piu' un'esecuzione unica con ottimizzazione 2 minuti dopo la
  migrazione (fuori dai 600 s del deploy). Sui core senza dispatcher
  (NormaFlow) le stesse migrazioni creano un cron_job giornaliero.


```

**Step 3: Riga in `[CORE]`**, dopo la voce "Migrazione m261030_130000_v2_2_5_request_uri_safe_filters ...":

```
- Migrazioni di manutenzione: m261102_160000_v2_2_5_schedule_purge_audit,
  m261102_160100_v2_2_5_schedule_purge_spool (programmano le pulizie, vedi
  [MANUTENZIONE DB]).
```

**Step 4: Righe in `[DOPO IL DEPLOY]`**, in fondo alla sezione:

```
- Pulizie del DB: nei log del dispatcher (job "Manutenzione: pulizia audit
  intelligence" e "Manutenzione: pulizia commands_spool") verificare
  l'esecuzione unica partita circa 2 minuti dopo le migrazioni: righe
  cancellate e ALTER eseguito o rinviato (con il motivo). Se una migrazione
  ha scritto un avviso sull'esecuzione unica, lanciare a mano i due comandi
  con --optimize=1.
- Controllare la dimensione delle tabelle (information_schema.tables:
  data_length, index_length, data_free di intelligence_db_audit e
  commands_spool) e lo spazio del volume MariaDB (df sull'host). Se
  l'ottimizzazione e' rinviata per spazio non verificabile, impostare il
  parametro dbDiskProbePath (/var/www/html: su NS8 e' sullo stesso filesystem
  del volume mariadb-data, da confermare con df) e rilanciare il comando.
- Prima del deploy: verificare che il binlog di MariaDB sia spento (log_bin)
  o che ci sia spazio per le DELETE grandi della prima esecuzione.
```

**Step 5: Verifica**

```bash
podman exec soundorgan-php bash -c 'grep -c "MANUTENZIONE DB" /var/www/html/CHANGELOG-v2.2.5.txt; grep -n "m261102_160000_v2_2_5\|Pulizie del DB" /var/www/html/CHANGELOG-v2.2.5.txt'
```
Expected: `2` (titolo della sezione e rimando in [CORE]); trovate la riga di [CORE] e quella di [DOPO IL DEPLOY].

**Step 6: Commit**

```bash
podman exec -w /var/www/html soundorgan-php git add CHANGELOG-v2.2.5.txt
podman exec -w /var/www/html soundorgan-php git -c user.name="Claude Code" -c user.email=claude1@sharkom.net commit -m "docs: changelog v2.2.5 con la retention di intelligence_db_audit e commands_spool"
```

---

### Task 13: Verifica finale delle suite

Tutti i repo. Nessuna modifica: solo comandi ed esiti. Se qualcosa fallisce, usare `sharkom-tools:systematic-debugging` prima di toccare il codice.

**Step 1: dev-helper, suite completa**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-dev-helper soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/phpunit
```
Expected: `OK`, nessun errore né fallimento (i 18 test nuovi più quelli esistenti).

**Step 2: intelligence, suite completa**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/intelligence/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit
```
Expected: `OK`. Eventuali fallimenti in `TriggerSuTabelleEscluseTest` o negli altri file in lavorazione dell'altro agente vanno riportati, non corretti.

**Step 3: yii2-cron, suite completa**

```bash
podman exec -u www-data -w /var/www/html/vendor/sharkom/yii2-cron/tests soundorgan-php flock /tmp/soundorgan_test.lock /var/www/html/vendor/bin/codecept run unit
```
Expected: `OK (26 tests, ...)` (12 esistenti, più 1 + 4 + 5 + 3 + 1 nuovi).

**Step 4: Nessuna tabella di prova rimasta sul DB di test**

```bash
podman exec soundorgan-php bash -c 'export MYSQL_PWD=$MARIADB_SO_PASS; mysql -h soundorgan-mariadb -u$MARIADB_SO_USER soundorgan_test -N -e "SHOW TABLES LIKE \"zz_test_retention_%\""'
```
Expected: nessuna riga.

**Step 5: Route registrate e stato dei repo**

```bash
podman exec -u www-data -w /var/www/html soundorgan-php php yii help intelligence/maintenance/purge-audit
podman exec -u www-data -w /var/www/html soundorgan-php php yii help cron/maintenance/purge-spool
for r in yii2-dev-helper yii2-cron; do podman exec -w /var/www/html/vendor/sharkom/$r soundorgan-php bash -c 'echo "== $(basename $PWD) $(git branch --show-current)"; git status --short; git log --oneline -6'; done
podman exec -w /var/www/html/vendor/sharkom/intelligence soundorgan-php bash -c 'git branch --show-current; git status --short; git log --oneline -6'
podman exec -w /var/www/html soundorgan-php bash -c 'git branch --show-current; git status --short; git log --oneline -3'
```
Expected: entrambi gli help rispondono. dev-helper e yii2-cron sono puliti sui loro branch. In intelligence restano solo le modifiche dell'altro agente. Il core è pulito su `dev-adminlte_v2-2.2.6`.

**Step 6: Da ricordare per il rilascio (non in questo piano)**

- Prima della composer update del core vanno fatti tre merge: dev-helper `retention-pulizie` → `unified` (il core usa `dev-unified as dev-master`), yii2-cron `retention-pulizie` → `master`, intelligence `fix-trigger-esclusioni` → `master`.
- NormaFlow: i comandi arrivano con i branch che NormaFlow usa per intelligence, yii2-cron e dev-helper (`normaflow`); fino a quel merge su NormaFlow non cambia nulla.
- Non lanciare i comandi di pulizia sul DB dev senza l'ok dell'utente: ci pensa il deploy.

<?php
// community_help.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$pdo = getPdo();
$user = currentUser();

$message_success = '';
$message_error = '';

// Skundo priėmimo logika
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    if (!$user) {
        $message_error = 'Turite būti prisijungęs, kad pateiktumėte skundą.';
    } else {
        $order_id = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
        $type = $_POST['type'] ?? 'general';
        $message = trim($_POST['message'] ?? '');

        if (empty($message)) {
            $message_error = 'Prašome įvesti skundo tekstą.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO community_tickets (user_id, order_id, type, message, status, created_at)
                VALUES (:user_id, :order_id, :type, :message, 'open', NOW())
            ");
            $stmt->execute([
                'user_id' => $user['id'],
                'order_id' => $order_id,
                'type' => $type,
                'message' => $message
            ]);
            $message_success = 'Jūsų skundas sėkmingai pateiktas. Administratoriai su jumis susisieks.';
        }
    }
}

// Ištraukiame vartotojo užsakymus dropdown pasirinkimui
$user_orders = [];
if ($user) {
    $stmt = $pdo->prepare("
        SELECT id, created_at, total_amount
        FROM community_orders 
        WHERE buyer_id = :uid1 OR seller_id = :uid2
        ORDER BY created_at DESC
    ");
    $stmt->execute([
        'uid1' => $user['id'],
        'uid2' => $user['id']
    ]);
    $user_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (function_exists('headerStyles')) {
    echo headerStyles();
}
?>
<style>
    :root {
      --bg: #f7f7fb;
      --card: #ffffff;
      --border: #e4e7ec;
      --text-main: #0f172a;
      --text-muted: #475467;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
    }
    * { box-sizing: border-box; }
    body { margin:0; background: var(--bg); color: var(--text-main); font-family: 'Inter', sans-serif; }
    a { color:inherit; text-decoration:none; transition: color .2s; }
    
    .page { max-width: 1200px; margin:0 auto; padding:32px 20px 72px; display:flex; flex-direction:column; gap:32px; }

    /* Hero Section */
    .hero { 
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border:1px solid #dbeafe; 
        border-radius:24px; 
        padding:40px; 
        display:flex; 
        align-items:center; 
        justify-content:space-between; 
        gap:32px; 
        flex-wrap:wrap; 
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .hero-content { max-width: 600px; flex: 1; }
    .hero h1 { margin:0 0 12px; font-size:32px; color:#1e3a8a; letter-spacing:-0.5px; }
    .hero p { margin:0; color:#1e40af; line-height:1.6; font-size:16px; }
    
    .pill { 
        display:inline-flex; align-items:center; gap:8px; 
        padding:6px 12px; border-radius:999px; 
        background:#fff; border:1px solid #bfdbfe; 
        font-weight:600; font-size:13px; color:#1e40af; 
        margin-bottom: 16px;
    }

    /* Cards */
    .card { 
        background:var(--card); 
        border:1px solid var(--border); 
        border-radius:20px; 
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }
    .card-body { padding: 32px; }

    /* Tabs */
    .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .tab-btn {
        background: #fff; border: 1px solid var(--border);
        color: var(--text-muted); font-weight: 600; font-size: 14px;
        padding: 10px 20px; border-radius: 999px; cursor: pointer;
        transition: all 0.2s;
    }
    .tab-btn:hover { border-color: var(--accent); color: var(--accent); }
    .tab-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }
    .tab-content { display: none; }
    .tab-content.active { display: block; animation: fadeIn 0.3s ease-in-out; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

    /* Text & Lists */
    .section-title { margin: 0 0 24px; font-size: 22px; color: var(--text-main); }
    .faq-category-title { margin: 32px 0 16px; font-size: 18px; color: var(--accent); border-bottom: 2px solid #eff6ff; padding-bottom: 8px; }
    .faq-category-title:first-of-type { margin-top: 0; }
    
    .faq-item { border-bottom: 1px solid var(--border); padding-bottom: 16px; margin-bottom: 16px; }
    .faq-item:last-child { border-bottom: none; padding-bottom: 0; margin-bottom: 0; }
    .faq-item h3 { margin: 0 0 8px; color: var(--text-main); font-size: 16px; font-weight: 600; }
    .faq-item p { margin: 0; color: var(--text-muted); line-height: 1.6; font-size: 14px; }
    
    ul.policy-list { margin: 0; padding-left: 20px; color: var(--text-muted); line-height: 1.6; font-size: 14px;}
    ul.policy-list li { margin-bottom: 8px; }
    ul.policy-list strong { color: var(--text-main); }

    /* Forms */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: var(--text-main); }
    .form-control {
        width: 100%; padding: 12px 16px;
        border: 1px solid var(--border); border-radius: 10px;
        font-family: inherit; font-size: 14px;
        transition: border-color 0.2s;
        background: #fff; color: var(--text-main);
    }
    .form-control:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    textarea.form-control { resize: vertical; min-height: 120px; }

    /* Buttons */
    .btn { 
        padding:12px 24px; border-radius:10px; 
        font-weight:600; font-size:14px;
        cursor:pointer; text-decoration:none; 
        display:inline-flex; align-items:center; justify-content:center;
        transition: all .2s;
        border:none; background: #0f172a; color:#fff;
    }
    .btn:hover { background: #1e293b; color:#fff; transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

    /* Notices */
    .notice { padding:12px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; gap:10px; align-items:center; }
    .notice.success { background: #ecfdf5; border: 1px solid #d1fae5; color: #065f46; }
    .notice.error { background: #fef2f2; border: 1px solid #fee2e2; color: #991b1b; }

    @media (max-width: 768px) {
        .hero { padding: 24px; flex-direction: column; align-items: stretch; }
    }
</style>

<?php renderHeader($pdo, 'community_help'); ?>

<div class="page">
    <section class="hero">
        <div class="hero-content">
            <div class="pill">🆘 Pagalba</div>
            <h1>Pagalba ir Taisyklės</h1>
            <p>Raskite atsakymus į dažniausiai užduodamus klausimus, sužinokite apie grąžinimus arba susisiekite su administracija ginčų atveju.</p>
        </div>
    </section>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('faq', event)">D.U.K. ir Taisyklės</button>
        <button class="tab-btn" onclick="showTab('ticket', event)">Pateikti skundą</button>
    </div>

    <div id="faq" class="card tab-content active">
        <div class="card-body">
            
            <h2 class="faq-category-title">Bendra informacija</h2>
            <div class="faq-item">
                <h3>1. Kas yra bendruomenės turgelis?</h3>
                <p>Tai platformos erdvė, kurioje registruoti vartotojai gali pirkti, parduoti ar ieškoti įvairių priemonių, paslaugų bei daiktų, susijusių su mūsų bendruomenės pomėgiais.</p>
            </div>
            <div class="faq-item">
                <h3>2. Ar man reikia paskyros norint naudotis turgeliu?</h3>
                <p>Taip. Naršyti skelbimus galite ir be paskyros, tačiau norėdami įdėti savo skelbimą, matyti pardavėjų kontaktus arba atlikti pirkimą, privalote būti prisijungę.</p>
            </div>
            <div class="faq-item">
                <h3>3. Kuo skiriasi „Parduoda“ ir „Ieško“ skelbimai?</h3>
                <p>„Parduoda“ skelbimai reiškia, kad vartotojas turi konkrečią prekę ir nori ją parduoti (ją galima įdėti į krepšelį). „Ieško“ skelbimai yra skirti vartotojams, kurie neranda norimos prekės ir nori paskelbti savo biudžetą bei pageidavimus, kad kiti nariai jiems ką nors pasiūlytų.</p>
            </div>
            <div class="faq-item">
                <h3>4. Kiek kainuoja įkelti skelbimą?</h3>
                <p>Skelbimų įkėlimas platformoje yra visiškai nemokamas. Mokesčiai taikomi tik atliekant sėkmingą pardavimo transakciją per sistemą.</p>
            </div>

            <h2 class="faq-category-title">Pirkėjams</h2>
            <div class="faq-item">
                <h3>5. Kaip veikia saugus pirkimas (Escrow)?</h3>
                <p>Apmokėjus už prekę per platformą, jūsų lėšos yra saugiai rezervuojamos „Stripe“ sistemoje. Pardavėjas pinigus gauna tik tada, kai jūs platformoje patvirtinate, jog sėkmingai gavote prekę ir ji atitinka aprašymą.</p>
            </div>
            <div class="faq-item">
                <h3>6. Kaip galiu apmokėti už prekę?</h3>
                <p>Turgelio skelbimuose esančias prekes galite įsidėti į krepšelį ir atsiskaityti įprastais mokėjimo būdais (kredito/debeto kortele, Apple Pay ir t.t.) per integruotą saugią „Stripe“ sistemą.</p>
            </div>
            <div class="faq-item">
                <h3>7. Per kiek laiko privalau patvirtinti siuntos gavimą?</h3>
                <p>Gavę siuntą, privalote per 3 dienas nuo faktinio pristatymo patvirtinti gavimą sistemoje. Jei to nepadarysite, pardavėjas turės teisę prašyti sistemos priverstinio lėšų išmokėjimo.</p>
            </div>
            <div class="faq-item">
                <h3>8. Ką daryti, jeigu prekė neatkeliavo?</h3>
                <p>Jeigu praėjo sutartas pristatymo terminas, bet prekės negavote, pirmiausia susisiekite su pardavėju. Jei situacija nesikeičia, per 3 dienas atidarykite ginčą skiltyje „Pateikti skundą“.</p>
            </div>
            <div class="faq-item">
                <h3>9. Ką daryti, jeigu gauta prekė yra sugadinta arba neatitinka aprašymo?</h3>
                <p>Nepatvirtinkite prekės gavimo. Nedelsiant (per 3 dienas nuo pristatymo) užpildykite skundą ir pasirinkite „Prekė neatitinka aprašymo“. Būkite pasiruošę pateikti nuotraukas ir kitus įrodymus administracijai.</p>
            </div>
            <div class="faq-item">
                <h3>10. Ar galiu atšaukti užsakymą prieš išsiunčiant?</h3>
                <p>Taip, bet turite kuo skubiau susisiekti su pardavėju per asmenines žinutes, kol jis nespėjo išsiųsti prekės, ir pateikti prašymą grąžinti pinigus.</p>
            </div>

            <h2 class="faq-category-title">Pardavėjams</h2>
            <div class="faq-item">
                <h3>11. Kaip įkelti pardavimo skelbimą?</h3>
                <p>Paspaudę mygtuką „Įdėti skelbimą“ turgelio puslapyje, užpildykite visą informaciją: pasirinkite tipą „Parduodu“, įkelkite nuotraukas, detaliai aprašykite būklę bei nurodykite kainą.</p>
            </div>
            <div class="faq-item">
                <h3>12. Kada ir kaip gausiu pinigus už parduotą prekę?</h3>
                <p>Kai išsiunčiate prekę ir pirkėjas paspaudžia mygtuką „Gavau prekę“, lėšos (atskaičius komisinį mokestį) automatiškai pervedamos į jūsų susietą „Stripe“ banko sąskaitą.</p>
            </div>
            <div class="faq-item">
                <h3>13. Ar platforma taiko komisinius mokesčius pardavėjui?</h3>
                <p>Taip. Kad galėtume palaikyti saugius mokėjimus ir administruoti platformą, nuo sėkmingo pardavimo atskaičiuojamas nedidelis platformos komisinis mokestis (tikslus % matomas jūsų Stripe Connect paskyroje).</p>
            </div>
            <div class="faq-item">
                <h3>14. Kas yra „Priverstinis išmokėjimas“ (Force Payout)?</h3>
                <p>Jei pirkėjas ignoruoja žinutes ir ilgiau nei 3 dienas nepatvirtina gavimo, nors siuntų tarnybos sekimo numeris rodo, kad siunta įteikta, jūs galite pateikti skundą. Administracija patikrins įrodymus ir išmokės pinigus priverstinai.</p>
            </div>
            <div class="faq-item">
                <h3>15. Kaip išsiųsti prekę pirkėjui?</h3>
                <p>Gavę užsakymą, saugiai supakuokite prekę, išsiųskite pirkėjo nurodytu adresu arba į pasirinktą paštomatą. Būtinai išsaugokite siuntos sekimo (tracking) numerį ir pasidalinkite juo su pirkėju.</p>
            </div>
            <div class="faq-item">
                <h3>16. Ką daryti, jei pirkėjas melagingai teigia, kad negavo prekės?</h3>
                <p>Jei turite siuntos sekimo numerį, įrodantį įteikimą, pateikite jį administracijai ginčo metu. Sprendimas dažniausiai bus priimtas jūsų naudai.</p>
            </div>
            <div class="faq-item">
                <h3>17. Kaip redaguoti arba ištrinti savo skelbimą?</h3>
                <p>Savo įkeltus skelbimus galite valdyti nuėję į skiltį „Mano paskyra“ -> „Mano skelbimai“. Ten galėsite keisti kainą, aprašymą ar pažymėti prekę kaip neparduodamą / ištrinti.</p>
            </div>

            <h2 class="faq-category-title">Grąžinimai ir ginčų sprendimas</h2>
            <div class="faq-item">
                <h3>18. Kaip iškelti ginčą ar pateikti skundą?</h3>
                <p>Šiame puslapyje pereikite į kortelę „Pateikti skundą“. Pasirinkite susijusį užsakymą, nurodykite problemos tipą ir detaliai aprašykite situaciją.</p>
            </div>
            <div class="faq-item">
                <h3>19. Kokiomis sąlygomis galiu atgauti pinigus (Refund)?</h3>
                <p>Pinigai grąžinami, jeigu prekė apskritai nebuvo išsiųsta, dingo tranzito metu, arba gauta prekė kardinaliai neatitinka skelbimo aprašymo (yra sugedusi, kita spalva, kt.) ir pirkėjas su pardavėju nesusitaria kitaip.</p>
            </div>
            <div class="faq-item">
                <h3>20. Kas priima galutinį sprendimą kilus ginčui?</h3>
                <p>Jei pirkėjas ir pardavėjas neranda bendro sutarimo, platformos administracija įvertina abiejų šalių pateiktus įrodymus (nuotraukas, susirašinėjimus, sekimo numerius) ir priima galutinį, neskundžiamą sprendimą.</p>
            </div>
            <div class="faq-item">
                <h3>21. Kiek laiko trunka ginčo nagrinėjimas?</h3>
                <p>Ginčai paprastai peržiūrimi per 1-3 darbo dienas. Kartais procesas gali užtrukti ilgiau, jei reikalinga papildoma informacija iš abiejų šalių.</p>
            </div>
            <div class="faq-item">
                <h3>22. Kaip atliekamas pinigų grąžinimas?</h3>
                <p>Jei priimamas sprendimas grąžinti lėšas pirkėjui, jos automatiškai pervedamos atgal į tą pačią kortelę arba banko sąskaitą, iš kurios buvo atliktas mokėjimas. Pinigų įskaitymas gali trukti 3–7 darbo dienas, priklausomai nuo banko.</p>
            </div>
            <div class="faq-item">
                <h3>23. Ar galiu grąžinti prekę tiesiog persigalvojęs?</h3>
                <p>Ne, platforma tarp privačių asmenų netaiko 14 dienų be priežasties grąžinimo taisyklės. Prekės grąžinamos tik tuo atveju, jei jos neatitinka skelbime nurodytos informacijos.</p>
            </div>

            <h2 class="faq-category-title">Saugumas ir taisyklės</h2>
            <div class="faq-item">
                <h3>24. Kokias prekes draudžiama pardavinėti platformoje?</h3>
                <p>Draudžiama parduoti nelegalius, suklastotus, pavogtus, pavojingus ar bet kokius kitus Lietuvos Respublikos įstatymams prieštaraujančius daiktus.</p>
            </div>
            <div class="faq-item">
                <h3>25. Kodėl nerekomenduojama atsiskaitinėti už platformos ribų?</h3>
                <p>Mokant pavedimu ar grynaisiais ne per mūsų sistemą, jūs prarandate platformos teikiamą „Saugaus pirkimo“ garantiją. Apgavystės atveju platformos administracija negalės padėti susigrąžinti lėšų.</p>
            </div>
            <div class="faq-item">
                <h3>26. Kas gresia už taisyklių pažeidimą ar sukčiavimą?</h3>
                <p>Nustačius sukčiavimo atvejį, piktybinį nepagarbų elgesį ar draudžiamų prekių prekybą, vartotojo paskyra yra nedelsiant visam laikui blokuojama, o informacija gali būti perduota atitinkamoms teisėsaugos institucijoms.</p>
            </div>
            <div class="faq-item">
                <h3>27. Ar galiu dalintis asmeniniais kontaktais skelbimo aprašyme?</h3>
                <p>Dėl jūsų pačių saugumo nerekomenduojame viešai skelbti telefonų numerių ar el. pašto adresų. Visą bendravimą raginame vykdyti per integruotą asmeninių žinučių sistemą.</p>
            </div>
        </div>
    </div>

    <div id="ticket" class="card tab-content">
        <div class="card-body">
            <h2 class="section-title">Pateikti skundą ar ginčą</h2>
            
            <?php if ($message_success): ?>
                <div class="notice success">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                    <?= htmlspecialchars($message_success) ?>
                </div>
            <?php endif; ?>
            <?php if ($message_error): ?>
                <div class="notice error">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <?= htmlspecialchars($message_error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$user): ?>
                <p style="color: var(--text-muted); margin-bottom: 0;">
                    <a href="/login.php" style="color: var(--accent); font-weight: 600; text-decoration: underline;">Prisijunkite</a>, kad pateiktumėte skundą.
                </p>
            <?php else: ?>
                <form method="POST" action="">
                    <?php if (function_exists('csrfField')) echo csrfField(); ?>
                    
                    <div class="form-group">
                        <label>Susijęs užsakymas (nebūtina)</label>
                        <select name="order_id" class="form-control">
                            <option value="">-- Kitas klausimas --</option>
                            <?php foreach ($user_orders as $o): ?>
                                <option value="<?= $o['id'] ?>">Užsakymas #<?= $o['id'] ?> (<?= number_format($o['total_amount'], 2) ?> €)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Problemos tipas</label>
                        <select name="type" class="form-control" required>
                            <option value="item_not_received">Prekė negauta (Pirkėjams)</option>
                            <option value="item_defective">Prekė neatitinka aprašymo (Pirkėjams)</option>
                            <option value="buyer_unresponsive">Pirkėjas nepatvirtina gavimo (Pardavėjams)</option>
                            <option value="fraud">Kitas sukčiavimas</option>
                            <option value="general">Bendras klausimas</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Detalus situacijos aprašymas</label>
                        <textarea name="message" class="form-control" required placeholder="Aprašykite problemą, nurodykite siuntos numerį, jei turite..."></textarea>
                    </div>

                    <button type="submit" name="submit_ticket" class="btn">Siųsti skundą</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function showTab(tabId, event) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        
        document.getElementById(tabId).classList.add('active');
        if (event && event.currentTarget) {
            event.currentTarget.classList.add('active');
        }
    }
</script>

<?php renderFooter($pdo); ?>

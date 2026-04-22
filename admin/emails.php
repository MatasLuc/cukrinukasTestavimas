<?php
// admin/emails.php

// Paimame vartotojų sąrašą
$stmt = $pdo->query("SELECT id, name, email FROM users ORDER BY name ASC");
$users = $stmt->fetchAll();
?>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    /* Paprasto redaktoriaus stilius */
    .simple-editor-wrapper {
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        overflow: hidden;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        font-family: 'Inter', sans-serif;
    }
    .editor-toolbar {
        background: #f8fafc;
        border-bottom: 1px solid #e5e7eb;
        padding: 10px;
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center;
    }
    .editor-btn {
        background: #fff;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        cursor: pointer;
        padding: 6px 12px;
        font-size: 14px;
        font-weight: 600;
        min-width: 36px;
        color: #4b5563;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .editor-btn:hover {
        background: #f1f5f9;
        color: #111827;
        border-color: #9ca3af;
    }
    #editor-visual {
        min-height: 450px;
        padding: 32px;
        outline: none;
        overflow-y: auto;
        font-family: 'Inter', Helvetica, Arial, sans-serif;
        font-size: 16px;
        line-height: 1.6;
        color: #475467; /* Atitinka mailer.php tekstą */
        background-color: #ffffff;
    }
    #editor-visual:focus {
        background-color: #fafafa;
    }
    /* Elementų stiliai pačiame redaktoriuje, kad matytųsi kaip laiške */
    #editor-visual h2 {
        color: #0f172a;
        font-weight: 700;
        margin-top: 0;
    }
    #editor-visual a {
        color: #2563eb;
        text-decoration: underline;
    }
    #editor-visual blockquote {
        border-left: 4px solid #2563eb;
        margin-left: 0;
        padding-left: 16px;
        color: #64748b;
        background: #f8fafc;
        padding: 16px;
        border-radius: 0 12px 12px 0;
        font-style: italic;
    }
    
    /* Formos elementai */
    .form-label {
        display: block; 
        margin-bottom: 8px; 
        font-weight: 600; 
        color: #374151;
        font-size: 14px;
    }
    .form-input, .form-select {
        width: 100%; 
        padding: 10px 12px; 
        border-radius: 8px; 
        border: 1px solid #d1d5db; 
        background-color: #fff; 
        font-size: 14px;
        font-family: inherit;
        transition: border-color 0.2s;
    }
    .form-input:focus, .form-select:focus {
        border-color: #2563eb;
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }
    
    /* Select grupavimas */
    optgroup { font-weight: 700; color: #2563eb; }
</style>

<div class="card" style="border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #e5e7eb;">
        <h3 style="margin:0; color:#111827;">📧 Siųsti laišką</h3>
    </div>

    <form action="admin.php?view=emails" method="POST" onsubmit="syncContent(); return confirm('Ar tikrai norite siųsti šį laišką?');">
        <?php echo csrfField(); ?>
        
        <input type="hidden" name="action" value="send_email">
        
        <div class="grid grid-2" style="gap: 20px;">
            <div>
                <label class="form-label">Gavėjas</label>
                <select name="recipient_id" id="recipientSelect" required class="form-select">
                    <option value="">-- Pasirinkite gavėją --</option>
                    
                    <option value="manual" style="font-weight:bold; color:#059669;">✍️ Įvesti el. paštą rankiniu būdu</option>
                    <option value="all" style="font-weight:bold; color:#2563eb;">📢 SIŲSTI VISIEMS KLIENTAMS (<?php echo count($users); ?>)</option>
                    <option disabled>--------------------------------</option>
                    
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>">
                            <?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['email']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <div id="manualEmailContainer" style="display:none; margin-top: 15px;">
                    <label class="form-label" style="color:#059669;">Įveskite gavėjo el. paštą:</label>
                    <input type="email" name="manual_email" id="manualEmailInput" placeholder="pvz.: klientas@gmail.com" class="form-input">
                </div>
            </div>

            <div>
                <label class="form-label">Šablonas (greitas užpildymas)</label>
                <select id="templateSelector" class="form-select" style="background-color:#f8fafc;">
                    <option value="">-- Pasirinkite šabloną --</option>
                    
                    <optgroup label="✨ Bendra komunikacija">
                        <option value="welcome">👋 Sveiki atvykę (Registracija)</option>
                        <option value="order_processing">⚙️ Užsakymas ruošiamas</option>
                        <option value="order_ready_pickup">🏬 Paruošta atsiėmimui</option>
                        <option value="order_shipped">📦 Užsakymas išsiųstas</option>
                        <option value="order_delivered">✅ Užsakymas pristatytas</option>
                        <option value="payment_failed">💳 Nepavykęs mokėjimas</option>
                        <option value="order_returned">🔄 Užsakymas grąžintas</option>
                        <option value="refund_processed">💶 Pinigų grąžinimas</option>
                        <option value="feedback">⭐ Atsiliepimo prašymas</option>
                        <option value="review_reminder_followup">🌟 Antras atsiliepimo priminimas</option>
                        <option value="apology">😔 Atsiprašymas dėl vėlavimo</option>
                        <option value="restock">🔄 Prekė vėl prekyboje</option>
                        <option value="pwd_changed">🔐 Slaptažodis pakeistas</option>
                        <option value="account_inactive">⚠️ Paskyros neaktyvumas</option>
                        <option value="newsletter_confirm">📬 Naujienlaiškio patvirtinimas</option>
                        <option value="weekly_digest">📰 Savaitės naujienų apžvalga</option>
                        <option value="monthly_digest">📅 Mėnesio saldžiausi</option>
                        <option value="ticket_opened">🎫 Pagalbos užklausa gauta</option>
                        <option value="ticket_resolved">✅ Pagalbos užklausa išspręsta</option>
                        <option value="storage_tips">💡 Kaip laikyti saldumynus?</option>
                        <option value="allergen_info">🥜 Informacija apie alergenus</option>
                        <option value="pre_order_available">⏰ Išankstinis užsakymas</option>
                        <option value="pre_order_shipped">🚀 Išankstinis užsakymas pakeliui</option>
                        <option value="delivery_address_update">🏠 Pristatymo adreso patvirtinimas</option>
                        <option value="subscription_renewal">🔁 Prenumeratos atnaujinimas</option>
                    </optgroup>

                    <optgroup label="🔥 Pasiūlymai ir Akcijos">
                        <option value="promo">🎉 Bendras išpardavimas (-20%)</option>
                        <option value="flash_sale_24h">⚡ 24 valandų išpardavimas</option>
                        <option value="weekend_madness">🎊 Savaitgalio beprotybė</option>
                        <option value="cart_recovery">🛒 Paliktas krepšelis</option>
                        <option value="new_arrival">✨ Naujienos parduotuvėje</option>
                        <option value="bogo_offer">✌️ Pirk 1, gauk 2 (BOGO)</option>
                        <option value="mix_box_promo">🎨 Susikurk savo rinkinį</option>
                        <option value="vegan_sweets">🌱 Veganiškų skanėstų akcija</option>
                        <option value="sugar_free_promo">🍏 Be pridėtinio cukraus</option>
                        <option value="vip_invite">💎 Kvietimas į VIP klubą</option>
                        <option value="anniversary_1yr">🥂 Metų sukaktis kartu</option>
                        <option value="order_10_milestone">🏆 Jūsų 10-asis užsakymas!</option>
                        <option value="loyalty_points">💰 Lojalumo taškų priminimas</option>
                        <option value="double_points">📈 Dvigubų taškų savaitgalis</option>
                        <option value="points_expiring">⏳ Jūsų taškai greitai baigs galioti</option>
                        <option value="referral">🤝 Pakviesk draugą</option>
                        <option value="miss_you_30">🥺 Pasiilgome Jūsų (30 d.)</option>
                        <option value="miss_you_90">💔 Sugrįžkite! (90 d. + nuolaida)</option>
                        <option value="collab_launch">🤝 Nauja partnerystė ir skoniai</option>
                        <option value="mystery_box">🎁 Paslapčių dėžutė (Mystery Box)</option>
                        <option value="free_shipping_weekend">🚚 Nemokamo pristatymo savaitgalis</option>
                        <option value="wholesale_invite">📦 Pasiūlymas didmenai/įmonėms</option>
                        <option value="bulk_buy">⚖️ Perkant daugiau – pigiau</option>
                        <option value="app_download">📱 Atsisiųskite mūsų programėlę</option>
                        <option value="social_media_contest">📸 Dalyvaukite konkurse</option>
                        <option value="secret_sale">🤫 Slaptas išpardavimas (tik prenumeratoriams)</option>
                        <option value="survey">📝 Trumpa apklausa</option>
                        <option value="summer_sale">☀️ Vasaros išpardavimas</option>
                        <option value="winter_sale">❄️ Žiemos išpardavimas</option>
                    </optgroup>

                    <optgroup label="📅 Šventės ir Progos">
                        <option value="birthday">🎂 Gimtadienio sveikinimas</option>
                        <option value="seasonal_christmas">🎄 Kalėdos</option>
                        <option value="pre_christmas_prep">🎁 Pasiruošimas Kalėdoms</option>
                        <option value="new_year">🎆 Naujieji Metai</option>
                        <option value="seasonal_easter">🥚 Velykos</option>
                        <option value="seasonal_valentines">💖 Valentino diena</option>
                        <option value="womens_day">🌷 Moters diena</option>
                        <option value="mens_day">🕶️ Vyro diena</option>
                        <option value="mothers_day">🌸 Motinos diena</option>
                        <option value="fathers_day">👔 Tėvo diena</option>
                        <option value="childrens_day">🎈 Vaikų gynimo diena</option>
                        <option value="jonines">🌿 Joninės</option>
                        <option value="seasonal_halloween">🎃 Helovinas</option>
                        <option value="singles_day">🛒 Vienišių diena (11.11)</option>
                        <option value="black_friday">⚫ Black Friday</option>
                        <option value="cyber_monday">💻 Cyber Monday</option>
                        <option value="back_to_school">🎒 Atgal į mokyklą</option>
                        <option value="teachers_day">📚 Mokytojų diena</option>
                        <option value="boss_day">💼 Boso diena</option>
                        <option value="graduation">🎓 Mokslo metų pabaiga / Išleistuvės</option>
                        <option value="name_day">🏷️ Vardadienio sveikinimas</option>
                    </optgroup>
                </select>
            </div>
        </div>

        <div style="margin-top:20px;">
            <label class="form-label">Laiško tema</label>
            <input type="text" name="subject" id="emailSubject" required placeholder="pvz.: Savaitgalio išpardavimas!" class="form-input">
        </div>

        <div style="margin-top:20px;">
            <label class="form-label">Laiško turinis</label>
            
            <textarea name="message" id="hiddenMessage" style="display:none;"></textarea>

            <div class="simple-editor-wrapper">
                <div class="editor-toolbar">
                    <button type="button" class="editor-btn" onclick="execCmd('bold')" title="Paryškinti"><b>B</b></button>
                    <button type="button" class="editor-btn" onclick="execCmd('italic')" title="Pasviras"><i>I</i></button>
                    <button type="button" class="editor-btn" onclick="execCmd('underline')" title="Pabraukti"><u>U</u></button>
                    <button type="button" class="editor-btn" onclick="execCmd('strikeThrough')" title="Perbraukti"><s>S</s></button>
                    <div style="width:1px; height:20px; background:#e5e7eb; margin:0 5px;"></div>
                    <button type="button" class="editor-btn" onclick="execCmd('justifyLeft')" title="Kairė">⬅️</button>
                    <button type="button" class="editor-btn" onclick="execCmd('justifyCenter')" title="Centras">↔️</button>
                    <div style="width:1px; height:20px; background:#e5e7eb; margin:0 5px;"></div>
                    <button type="button" class="editor-btn" onclick="execCmd('insertUnorderedList')" title="Sąrašas su taškais">• Sąrašas</button>
                    <button type="button" class="editor-btn" onclick="execCmd('insertOrderedList')" title="Numeruotas sąrašas">1. Sąrašas</button>
                    <div style="width:1px; height:20px; background:#e5e7eb; margin:0 5px;"></div>
                    <button type="button" class="editor-btn" onclick="createLink()" title="Įterpti nuorodą">🔗</button>
                    <button type="button" class="editor-btn" onclick="execCmd('unlink')" title="Panaikinti nuorodą">❌🔗</button>
                    <div style="width:1px; height:20px; background:#e5e7eb; margin:0 5px;"></div>
                    <button type="button" class="editor-btn" onclick="execCmd('removeFormat')" title="Išvalyti formatavimą">🧹</button>
                </div>
                
                <div id="editor-visual" contenteditable="true"></div>
            </div>

            <p class="text-muted" style="font-size:13px; margin-top:8px; color:#6b7280; display:flex; align-items:center; gap:6px;">
                <span>💡</span> <b>Patarimas:</b> Jūsų tekstas bus automatiškai įdėtas į naująjį „Cukrinukas“ dizaino šabloną (su logotipu ir rėmeliu).
            </p>
        </div>

        <div style="margin-top:24px; text-align:right;">
            <button type="submit" class="btn" style="background: #2563eb; color: white; padding: 12px 28px; font-weight: 600; border-radius: 12px; border: none; cursor: pointer; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2); transition: background 0.2s;">
                Siųsti laišką 🚀
            </button>
        </div>
    </form>
</div>

<script>
// --- Paprasto redaktoriaus funkcijos ---
function execCmd(command) {
    document.execCommand(command, false, null);
    document.getElementById('editor-visual').focus();
}

function createLink() {
    const url = prompt("Įveskite nuorodą (pvz., https://cukrinukas.lt):", "https://");
    if (url) {
        document.execCommand("createLink", false, url);
    }
}

// Prieš siunčiant formą, perkeliam turinį iš DIV į TEXTAREA
function syncContent() {
    const visualContent = document.getElementById('editor-visual').innerHTML;
    document.getElementById('hiddenMessage').value = visualContent;
}

document.getElementById('editor-visual').addEventListener('input', syncContent);

// --- Gavėjo pasirinkimo logika ---
document.getElementById('recipientSelect').addEventListener('change', function() {
    const manualInput = document.getElementById('manualEmailContainer');
    const inputField = document.getElementById('manualEmailInput');
    
    if (this.value === 'manual') {
        manualInput.style.display = 'block';
        inputField.setAttribute('required', 'required');
    } else {
        manualInput.style.display = 'none';
        inputField.removeAttribute('required');
        inputField.value = ''; // Išvalyti jei paslėpta
    }
});

// --- Stiliai naudojami šablonuose (Suderinti su account.php / mailer.php) ---
// Mygtuko stilius: --accent (#2563eb), rounded-12px, shadow
const styleBtn = 'background-color: #2563eb; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 12px; display: inline-block; font-weight: 600; font-size: 15px; margin-top: 16px; margin-bottom: 16px; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2); font-family: "Inter", sans-serif;';

// Antraštė: --text-main (#0f172a)
const styleH2 = 'color: #0f172a; font-size: 24px; margin-bottom: 20px; font-weight: 700; letter-spacing: -0.5px;';

// Tekstas: --text-muted (#475467)
const styleP = 'color: #475467; font-size: 16px; line-height: 1.6; margin-bottom: 16px;';

// Akcentas tekste: --accent (#2563eb)
const styleHighlight = 'color: #2563eb; font-weight: bold;';

// Dėžutė kodams: --bg (#f8fafc), --border (#e2e8f0)
const styleBox = 'background-color: #f8fafc; padding: 24px; border-radius: 16px; border: 1px solid #e2e8f0; margin: 24px 0; text-align: center;';

// Kodo stilius: dashed border su --accent
const styleCode = 'background-color: #ffffff; border: 2px dashed #2563eb; color: #2563eb; font-size: 22px; font-weight: 700; padding: 12px 24px; display: inline-block; border-radius: 8px; margin: 8px 0; letter-spacing: 1px;';

// --- Šablonų logika (75 vnt.) ---
const templates = {
    // ==========================================
    // ✨ BENDRA KOMUNIKACIJA (1 - 25)
    // ==========================================
    welcome: {
        subject: "Sveiki atvykę į Cukrinukas.lt šeimą! 👋",
        body: `<h2 style="${styleH2}">Sveiki atvykę!</h2>
<p style="${styleP}">Džiaugiamės, kad prisijungėte prie smaližių bendruomenės. Nuo šiol pirmieji sužinosite apie naujausius skanėstus, paslėptus skonius ir geriausius pasiūlymus, kurių niekur kitur nerasite.</p>
<p style="${styleP}">Mes tikime, kad kiekviena diena gali būti šiek tiek saldesnė. Norėdami padaryti pradžią dar geresnę, dovanojame Jums nuolaidą pirmajam apsipirkimui:</p>
<div style="${styleBox}">
    <span style="${styleCode}">SVEIKAS10</span>
    <p style="margin-top:12px; font-size:14px; color:#64748b;">Nuolaidos kodas suteikia -10% visam krepšeliui.</p>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products" style="${styleBtn}">Pradėti apsipirkimą</a>
</div>`
    },
    order_processing: {
        subject: "Gavome Jūsų užsakymą! ⚙️",
        body: `<h2 style="${styleH2}">Jūsų užsakymas sėkmingai gautas</h2>
<p style="${styleP}">Ačiū, kad perkate pas mus! Gavome Jūsų užsakymą ir mūsų komanda jau pradėjo jį ruošti. Kiekvieną skanėstą pakuojame atsargiai, kad Jus pasiektų nepriekaištingos būklės.</p>
<p style="${styleP}">Kai tik užsakymas bus perduotas kurjeriui, atsiųsime Jums atskirą laišką su siuntos sekimo nuoroda.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Sekti užsakymo būseną</a>
</div>`
    },
    order_ready_pickup: {
        subject: "Jūsų užsakymas paruoštas atsiėmimui! 🏬",
        body: `<h2 style="${styleH2}">Užsakymas laukia Jūsų!</h2>
<p style="${styleP}">Puikios naujienos – Jūsų surinktas saldumynų krepšelis jau paruoštas atsiėmimui mūsų fizinėje parduotuvėje!</p>
<div style="${styleBox}">
    <p style="${styleP} margin-bottom: 5px;"><strong>Atsiėmimo adresas:</strong> Saldžioji g. 12, Vilnius</p>
    <p style="${styleP} margin-bottom: 0;"><strong>Darbo laikas:</strong> I-V 10:00 - 19:00, VI 10:00 - 16:00</p>
</div>
<p style="${styleP}">Nepamirškite atvykus nurodyti savo užsakymo numerio. Iki pasimatymo!</p>`
    },
    order_shipped: {
        subject: "Jūsų užsakymas jau pakeliui! 🚚",
        body: `<h2 style="${styleH2}">Geros naujienos!</h2>
<p style="${styleP}">Jūsų užsakymas buvo kruopščiai supakuotas ir neseniai perduotas mūsų kurjeriams. Jau visai netrukus galėsite mėgautis savo pasirinktais skanėstais.</p>
<div style="${styleBox}">
    <p style="${styleP}; margin-bottom:0;">Siunta Jus pasieks per <strong>1-3 darbo dienas</strong>.</p>
</div>
<p style="${styleP}">Jei pasirinkote pristatymą į paštomatą, gausite atskirą SMS žinutę su atsiėmimo kodu, kai tik siunta bus įdėta.</p>
<p style="${styleP}">Tikimės, kad saldumynai Jums patiks!</p>`
    },
    order_delivered: {
        subject: "Užsakymas sėkmingai pristatytas! ✅",
        body: `<h2 style="${styleH2}">Skanaus!</h2>
<p style="${styleP}">Kurjerių tarnyba mus informavo, kad Jūsų užsakymas buvo sėkmingai pristatytas. Tikimės, kad viską gavote tvarkingai ir saldumynai pateisins Jūsų lūkesčius.</p>
<p style="${styleP}">Jei turite kokių nors pastabų dėl pakuotės ar pačių prekių, nedvejodami atsakykite į šį laišką.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Peržiūrėti užsakymą</a>
</div>`
    },
    payment_failed: {
        subject: "Mokėjimas nepavyko – Jūsų užsakymas laukia 💳",
        body: `<h2 style="${styleH2}">Ups... Iškilo nesklandumų</h2>
<p style="${styleP}">Pastebėjome, kad nepavyko sėkmingai apmokėti Jūsų paskutinio užsakymo. Nesijaudinkite, Jūsų pasirinktas prekes rezervavome dar 24 valandoms.</p>
<p style="${styleP}">Galite pabandyti atlikti mokėjimą dar kartą arba pasirinkti kitą atsiskaitymo būdą, paspaudę žemiau esančią nuorodą.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account/orders" style="${styleBtn}">Pakartoti mokėjimą</a>
</div>`
    },
    order_returned: {
        subject: "Patvirtiname Jūsų prekių grąžinimą 🔄",
        body: `<h2 style="${styleH2}">Grąžinimas gautas</h2>
<p style="${styleP}">Norime Jus informuoti, kad sėkmingai gavome Jūsų grąžintas prekes į mūsų sandėlį. Jas šiuo metu tikrina mūsų komanda.</p>
<p style="${styleP}">Pinigų grąžinimo procesas paprastai trunka nuo 3 iki 5 darbo dienų nuo šio patvirtinimo. Apie atliktą pervedimą informuosime atskiru laišku.</p>`
    },
    refund_processed: {
        subject: "Pinigų grąžinimas sėkmingai atliktas 💶",
        body: `<h2 style="${styleH2}">Pinigai grąžinti</h2>
<p style="${styleP}">Informuojame, kad pinigų grąžinimas už Jūsų grąžintas prekes (arba atšauktą užsakymą) buvo sėkmingai įvykdytas į tą pačią sąskaitą, iš kurios atlikote mokėjimą.</p>
<p style="${styleP}">Priklausomai nuo Jūsų banko, pinigai sąskaitą turėtų pasiekti per 1-3 darbo dienas. Dėkojame, kad išbandėte mūsų parduotuvę, ir tikimės Jus pamatyti ateityje!</p>`
    },
    feedback: {
        subject: "Kaip mums sekėsi? ⭐",
        body: `<h2 style="${styleH2}">Jūsų nuomonė mums labai svarbi!</h2>
<p style="${styleP}">Praėjo šiek tiek laiko nuo Jūsų paskutinio apsipirkimo Cukrinukas.lt parduotuvėje. Tikimės, kad skanėstai Jums patiko!</p>
<p style="${styleP}">Mes nuolat siekiame tobulėti, todėl būsime be galo dėkingi, jei skirsite minutę savo laiko ir paliksite atsiliepimą apie įsigytas prekes bei aptarnavimo kokybę.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Palikti atsiliepimą</a>
</div>`
    },
    review_reminder_followup: {
        subject: "Nepraleiskite progos pasidalinti nuomone 🌟",
        body: `<h2 style="${styleH2}">Vis dar laukiame Jūsų atsiliepimo</h2>
<p style="${styleP}">Prieš kelias dienas prašėme Jūsų įvertinti mūsų prekes. Žinome, kad kasdienybė būna užimta, tačiau Jūsų atviras atsiliepimas labai padeda kitiems pirkėjams priimti teisingą sprendimą!</p>
<p style="${styleP}">Kaip padėką už Jūsų laiką, palikus atsiliepimą Jums bus suteikta papildomų lojalumo taškų.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Įvertinti pirkinį</a>
</div>`
    },
    apology: {
        subject: "Nuoširdžiai atsiprašome dėl nesklandumų 😔",
        body: `<h2 style="${styleH2}">Atsiprašome, kad nenuvylėme...</h2>
<p style="${styleP}">Norime nuoširdžiai atsiprašyti dėl vėlavimo ar nesklandumų vykdant Jūsų užsakymą. Suprantame, kaip svarbu gauti viską laiku, ir mes labai vertiname Jūsų kantrybę bei supratingumą.</p>
<p style="${styleP}">Darome viską, kad tokios situacijos nepasikartotų ateityje. Kaip nedidelę kompensaciją už patirtus nepatogumus, prie kito užsakymo pridėsime dovanėlę arba galite pasinaudoti šia nuolaida:</p>
<div style="${styleBox}">
    <span style="${styleCode}">ATSIPRASOME15</span>
    <p style="margin-top:12px; font-size:14px; color:#64748b;">Suteikia -15% nuolaidą visam krepšeliui.</p>
</div>`
    },
    restock: {
        subject: "Jūsų laukta prekė vėl prekyboje! 🔄",
        body: `<h2 style="${styleH2}">Jos pagaliau sugrįžo!</h2>
<p style="${styleP}">Turime puikių žinių – prekė, kurios ieškojote ir laukėte, pagaliau vėl pasiekė mūsų sandėlio lentynas! Tačiau paskubėkite, nes šie skanėstai dingsta itin greitai, o kiekis ribotas.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products" style="${styleBtn}">Pirkti dabar</a>
</div>`
    },
    pwd_changed: {
        subject: "Jūsų paskyros slaptažodis buvo pakeistas 🔐",
        body: `<h2 style="${styleH2}">Saugumo pranešimas</h2>
<p style="${styleP}">Informuojame, kad Jūsų Cukrinukas.lt paskyros slaptažodis buvo sėkmingai pakeistas. Nuo šiol prisijungdami naudokite naująjį slaptažodį.</p>
<p style="${styleP}">Jei šį pakeitimą atlikote ne Jūs, nedelsiant susisiekite su mūsų klientų aptarnavimo komanda arba atkurkite slaptažodį paspaudę žemiau esančią nuorodą.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/forgot-password" style="${styleBtn}">Atkurti slaptažodį</a>
</div>`
    },
    account_inactive: {
        subject: "Jūsų paskyra greitai taps neaktyvi ⚠️",
        body: `<h2 style="${styleH2}">Ar vis dar esate su mumis?</h2>
<p style="${styleP}">Pastebėjome, kad prie savo Cukrinukas.lt paskyros neprisijungėte daugiau nei metus. Dėl duomenų saugumo taisyklių, netrukus būsime priversti deaktyvuoti Jūsų paskyrą.</p>
<p style="${styleP}">Jei norite išlaikyti savo paskyrą, sukauptus lojalumo taškus ir užsakymų istoriją, tiesiog prisijunkite prie jos per artimiausias 14 dienų.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/login" style="${styleBtn}">Prisijungti dabar</a>
</div>`
    },
    newsletter_confirm: {
        subject: "Prašome patvirtinti naujienlaiškio prenumeratą 📬",
        body: `<h2 style="${styleH2}">Liko tik vienas žingsnis!</h2>
<p style="${styleP}">Dėkojame, kad užsiprenumeravote mūsų naujienlaiškį. Prieš pradedant siųsti Jums saldžiausius pasiūlymus ir išskirtines nuolaidas, prašome patvirtinti savo el. pašto adresą.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/confirm-newsletter" style="${styleBtn}">Patvirtinti prenumeratą</a>
</div>
<p style="${styleP}">Jei šio prašymo nepateikėte, tiesiog ignoruokite šį laišką.</p>`
    },
    weekly_digest: {
        subject: "Šios savaitės saldžiausios naujienos! 📰",
        body: `<h2 style="${styleH2}">Savaitės apžvalga</h2>
<p style="${styleP}">Ar pasiruošėte saldžiam savaitgaliui? Šią savaitę mūsų parduotuvės lentynas papildė kelios ypatingos naujienos, kurių tiesiog negalite praleisti.</p>
<p style="${styleP}">Taip pat atrinkome pačius populiariausius šios savaitės skanėstus, kuriuos klientai šlavė nuo lentynų. Peržiūrėkite pilną apžvalgą mūsų puslapyje!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products?sort=popular" style="${styleBtn}">Žiūrėti populiariausius</a>
</div>`
    },
    monthly_digest: {
        subject: "Mėnesio geriausieji: ką praleidote? 📅",
        body: `<h2 style="${styleH2}">Mėnesio TOP skanėstai</h2>
<p style="${styleP}">Mėnuo prabėgo nepastebimai! Norime su Jumis pasidalinti produktais, kurie šį mėnesį sulaukė daugiausiai Jūsų meilės ir aukščiausių įvertinimų.</p>
<p style="${styleP}">Nuo klasikinių šokoladų iki egzotiškų guminukų – atraskite tai, ką dievina tūkstančiai kitų klientų.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products" style="${styleBtn}">Atrasti naujus skonius</a>
</div>`
    },
    ticket_opened: {
        subject: "Gavome Jūsų užklausą – netrukus susisieksime 🎫",
        body: `<h2 style="${styleH2}">Jūsų žinutė gauta</h2>
<p style="${styleP}">Dėkojame, kad susisiekėte su Cukrinukas.lt klientų aptarnavimo komanda. Jūsų užklausa sėkmingai užregistruota mūsų sistemoje.</p>
<p style="${styleP}">Vienas iš mūsų konsultantų peržiūrės Jūsų klausimą ir pateiks atsakymą per artimiausias 24 darbo valandas. Dėkojame už kantrybę!</p>`
    },
    ticket_resolved: {
        subject: "Jūsų pagalbos užklausa buvo išspręsta ✅",
        body: `<h2 style="${styleH2}">Klausimas išspręstas!</h2>
<p style="${styleP}">Mūsų komanda pažymėjo Jūsų pagalbos užklausą kaip išspręstą. Tikimės, kad pavyko atsakyti į visus Jūsų klausimus ir padėti išspręsti kilusius nesklandumus.</p>
<p style="${styleP}">Jei manote, kad problema vis dar išlieka arba turite papildomų klausimų, tiesiog atsakykite į šį laišką ir mes mielai padėsime toliau.</p>`
    },
    storage_tips: {
        subject: "Naudinga: kaip ilgiau išlaikyti saldumynus šviežius? 💡",
        body: `<h2 style="${styleH2}">Saldumynų laikymo paslaptys</h2>
<p style="${styleP}">Ar žinojote, kad netinkamai laikomas šokoladas gali prarasti savo blizgesį ir skonį? Norime, kad mūsų produktais mėgautumėtės ilgiau, todėl paruošėme trumpą gidą!</p>
<p style="${styleP}">Venkite tiesioginių saulės spindulių, nelaikykite šokolado šaldytuve (nebent lauke labai karšta) ir visada sandariai uždarykite guminukų pakuotes, kad jie nesukietėtų.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/blog" style="${styleBtn}">Skaityti daugiau patarimų</a>
</div>`
    },
    allergen_info: {
        subject: "Svarbi informacija apie alergenus ir sudėtis 🥜",
        body: `<h2 style="${styleH2}">Jūsų sveikata mums svarbiausia</h2>
<p style="${styleP}">Norime priminti, kad visų mūsų parduodamų prekių detalias sudėtis bei informaciją apie alergenus galite rasti kiekvienos prekės aprašyme.</p>
<p style="${styleP}">Jei esate alergiški riešutams, gliutenui ar kitiems ingredientams, atidžiai perskaitykite aprašymus. Atsiradus papildomų klausimų, mūsų komanda visada pasiruošusi Jums padėti atsirinkti saugius produktus!</p>`
    },
    pre_order_available: {
        subject: "Galimas išankstinis užsakymas naujienai! ⏰",
        body: `<h2 style="${styleH2}">Būkite pirmieji!</h2>
<p style="${styleP}">Ruošiame kažką ypatingo! Mūsų parduotuvėje netrukus pasirodys visiškai naujas riboto leidimo produktas, tačiau Jūs galite jį užsisakyti jau dabar.</p>
<p style="${styleP}">Pateikę išankstinį užsakymą, būsite tikri, kad šis skanėstas pasieks Jus iškart, kai tik jis atvyks į mūsų sandėlį.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products" style="${styleBtn}">Užsisakyti iš anksto</a>
</div>`
    },
    pre_order_shipped: {
        subject: "Jūsų išankstinis užsakymas išsiųstas! 🚀",
        body: `<h2 style="${styleH2}">Laukimas baigėsi!</h2>
<p style="${styleP}">Prekė, kurios taip ilgai laukėte, pagaliau atvyko į mūsų sandėlį. Mes nedelsdami supakavome Jūsų išankstinį užsakymą ir perdavėme jį kurjeriams.</p>
<p style="${styleP}">Dėkojame už Jūsų kantrybę. Jau greitai galėsite pasinerti į naujus skonius!</p>`
    },
    delivery_address_update: {
        subject: "Prašome patikslinti pristatymo adresą 🏠",
        body: `<h2 style="${styleH2}">Reikalingas adreso patikslinimas</h2>
<p style="${styleP}">Ruošdami Jūsų užsakymą pastebėjome, kad nurodytas pristatymo adresas gali būti nepilnas arba sistemoje įsivėlė klaida (pvz., trūksta buto numerio arba pašto kodo).</p>
<p style="${styleP}">Kad siunta Jus pasiektų laiku ir be trikdžių, prašome atsakyti į šį laišką su tiksliu savo pristatymo adresu.</p>`
    },
    subscription_renewal: {
        subject: "Jūsų skanėstų prenumerata atnaujinta! 🔁",
        body: `<h2 style="${styleH2}">Mėnesio saldumynų dozė paruošta</h2>
<p style="${styleP}">Informuojame, kad Jūsų kasmėnesinė saldumynų dėžutės prenumerata buvo sėkmingai atnaujinta. Šį mėnesį paruošėme dar daugiau siurprizų!</p>
<p style="${styleP}">Laukite kurjerio per artimiausias kelias dienas. Dėkojame, kad kiekvieną mėnesį pasitikite Cukrinukas.lt.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Valdyti prenumeratą</a>
</div>`
    },

    // ==========================================
    // 🔥 PASIŪLYMAI IR AKCIJOS (26 - 54)
    // ==========================================
    promo: {
        subject: "Saldus išpardavimas: -20% viskam! 🍭",
        body: `<h2 style="${styleH2}">Metas pasilepinti!</h2>
<p style="${styleP}">Tik šią savaitę <b>Cukrinukas.lt</b> parduotuvėje skelbiame visuotinį išpardavimą. Visiems saldumynams taikome <span style="${styleHighlight}">20% nuolaidą</span>, tad tai puiki proga papildyti savo atsargas.</p>
<p style="${styleP}">Panaudokite šį nuolaidos kodą atsiskaitymo metu:</p>
<div style="${styleBox}">
    <span style="${styleCode}">SALDU20</span>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Griebti nuolaidą</a>
</div>
<p style="font-size:13px; color:#94a3b8; text-align:center; margin-top:24px;">Pasiūlymas galioja iki sekmadienio vidurnakčio. Nuolaidos nesumuojamos.</p>`
    },
    flash_sale_24h: {
        subject: "⚡ ŽAIBIŠKAS IŠPARDAVIMAS: Tik 24 valandos!",
        body: `<h2 style="${styleH2}">Paskubėkite – laikas tiksi!</h2>
<p style="${styleP}">Skelbiame žaibišką 24 valandų išpardavimą! Nuo šios akimirkos populiariausiems mūsų produktams taikoma net iki 40% nuolaida.</p>
<p style="${styleP}">Kainos jau sumažintos sistemoje, jokių papildomų kodų vesti nereikia. Akcija baigsis lygiai po 24 valandų, todėl griebkite mėgstamiausius skanėstus dabar!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/sale" style="${styleBtn}">Žiūrėti akcijas</a>
</div>`
    },
    weekend_madness: {
        subject: "Savaitgalio beprotybė prasideda! 🎉",
        body: `<h2 style="${styleH2}">Saldus Savaitgalis!</h2>
<p style="${styleP}">Pamirškite dietas, savaitgalis skirtas pasilepinimui! Paruošėme specialius savaitgalio pasiūlymus, kuriais galite pasinaudoti nuo penktadienio vakaro iki sekmadienio vidurnakčio.</p>
<p style="${styleP}">Atraskite savaitgalio TOP 10 prekių su ypatingomis kainomis.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Pasinaudoti pasiūlymu</a>
</div>`
    },
    cart_recovery: {
        subject: "Jūsų krepšelis liūdi be Jūsų... 🛒",
        body: `<h2 style="${styleH2}">Ar kažką pamiršote?</h2>
<p style="${styleP}">Pastebėjome, kad įsidėjote prekių į krepšelį, bet užsakymo taip ir nebaigėte. Jūsų pasirinkti skanėstai vis dar laukia rezervuoti mūsų sistemoje!</p>
<p style="${styleP}">Galbūt iškilo nesklandumų? Grįžkite ir užbaikite užsakymą dabar – tai užtruks vos minutę. O kad sprendimas būtų lengvesnis, dovanojame Jums nemokamą pristatymą:</p>
<div style="${styleBox}">
    <span style="${styleCode}">PRISTATYMAS0</span>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/cart" style="${styleBtn}">Tęsti užsakymą</a>
</div>`
    },
    new_arrival: {
        subject: "Naujienos! Paragaukite pirmieji ✨",
        body: `<h2 style="${styleH2}">Ką tik atvyko!</h2>
<p style="${styleP}">Mūsų lentynas pasiekė visiškai nauji, dar neragauti skoniai! Nuo egzotiškų vaisinių guminukų iki išskirtinio rankų darbo šokolado, atkeliavusio tiesiai iš geriausių Europos meistrų.</p>
<p style="${styleP}">Būkite patys pirmieji, kurie išbandys šias naujienas, kol jos netapo visišku hitu ir nebuvo išparduotos.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products?sort=newest" style="${styleBtn}">Žiūrėti naujienas</a>
</div>`
    },
    bogo_offer: {
        subject: "Akcija: Pirk 1, gauk 2! ✌️",
        body: `<h2 style="${styleH2}">Dvigubai daugiau džiaugsmo!</h2>
<p style="${styleP}">Dalintis visada smagu, ypač kai tai nieko nekainuoja! Šią savaitę skelbiame legendinę BOGO (Buy One Get One) akciją atrinktiems produktams.</p>
<p style="${styleP}">Įsidėkite į krepšelį dvi vienodas akcines prekes ir už antrąją mokėti nereikės. Pasiūlymas puikiai tinka draugų susibūrimams arba tiesiog savo slaptų atsargų papildymui.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/products" style="${styleBtn}">Žiūrėti BOGO prekes</a>
</div>`
    },
    mix_box_promo: {
        subject: "Susikurkite savo tobulą rinkinį 🎨",
        body: `<h2 style="${styleH2}">Jūs esate skonių kūrėjas!</h2>
<p style="${styleP}">Kodėl rinktis vieną skonį, jei galite turėti juos visus? Šiandien siūlome Jums susikurti savo svajonių saldumynų dėžutę – rinkitės iš daugiau nei 50 skirtingų rūšių guminukų ir saldainių.</p>
<p style="${styleP}">Perkant 1 kg ar daugiau MIX rinkinio, taikome net 15% nuolaidą. Susikurkite savo unikalų derinį dabar!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/mix" style="${styleBtn}">Kurti savo dėžutę</a>
</div>`
    },
    vegan_sweets: {
        subject: "Atraskite veganiškų skanėstų pasaulį 🌱",
        body: `<h2 style="${styleH2}">Skanu ir draugiška gamtai</h2>
<p style="${styleP}">Saldumynai gali būti ne tik be galo skanūs, bet ir draugiški gyvūnams! Išplėtėme savo veganiškų prekių asortimentą – jokių gyvūninės kilmės ingredientų, jokios želatinos, tik tyras skonis.</p>
<p style="${styleP}">Kviečiame išbandyti naujuosius veganiškus guminukus su 10% nuolaida. Naudokite kodą:</p>
<div style="${styleBox}">
    <span style="${styleCode}">VEGAN10</span>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/vegan" style="${styleBtn}">Veganiški produktai</a>
</div>`
    },
    sugar_free_promo: {
        subject: "Saldumas be sąžinės graužaties 🍏",
        body: `<h2 style="${styleH2}">Mėgaukitės be pridėtinio cukraus!</h2>
<p style="${styleP}">Prižiūrite savo mitybą, bet vis tiek norisi kažko saldaus? Mes turime sprendimą! Mūsų kategorija „Be pridėtinio cukraus“ skirta būtent Jums.</p>
<p style="${styleP}">Saldinti natūraliais saldikliais, šie skanėstai turi mažiau kalorijų, bet išlaiko puikų skonį. Šią savaitę visai šiai kategorijai taikome specialias kainas.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/sugar-free" style="${styleBtn}">Produktai be cukraus</a>
</div>`
    },
    vip_invite: {
        subject: "Kvietimas į VIP klubą! 💎",
        body: `<h2 style="${styleH2}">Sveikiname prisijungus prie elito!</h2>
<p style="${styleP}">Pastebėjome Jūsų lojalumą mūsų parduotuvei. Dėl Jūsų aktyvumo ir meilės saldumynams, nusprendėme Jus pakviesti į uždarą Cukrinukas.lt VIP klientų klubą.</p>
<p style="${styleP}">Ką tai reiškia? Nuo šiol gausite išskirtinius pasiūlymus, slaptus išpardavimų kodus, nemokamus produktų testavimus ir visada pirmenybę klientų aptarnavime.</p>
<p style="${styleP}">Jūsų VIP statusas jau aktyvuotas. Ačiū, kad esate su mumis!</p>`
    },
    anniversary_1yr: {
        subject: "Mums jau metai kartu! 🥂 Dovana Jums",
        body: `<h2 style="${styleH2}">Lygiai metai nuo pirmojo apsipirkimo!</h2>
<p style="${styleP}">Šiandien – ypatinga diena. Lygiai prieš metus Jūs atlikote savo pirmąjį užsakymą Cukrinukas.lt parduotuvėje. Mes be galo vertiname tokius ištikimus klientus kaip Jūs.</p>
<p style="${styleP}">Norėdami atšvęsti šią progą kartu, dovanojame Jums 5€ kuponą kitam apsipirkimui, kuriam taikomas tik 15€ minimalaus krepšelio reikalavimas!</p>
<div style="${styleBox}">
    <span style="${styleCode}">METAI5</span>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Panaudoti kuponą</a>
</div>`
    },
    order_10_milestone: {
        subject: "Jubiliejinis 10-asis užsakymas! 🏆",
        body: `<h2 style="${styleH2}">Jūs esate tikras smaližius!</h2>
<p style="${styleP}">Sveikiname! Mūsų sistema rodo, kad ką tik atlikote savo 10-ąjį užsakymą. Tai nuostabus pasiekimas ir mes norime Jums asmeniškai padėkoti už tokį pasitikėjimą.</p>
<p style="${styleP}">Kartu su Jūsų užsakymu mes išsiuntėme ir specialią, niekur neparduodamą paslaptingą dovanėlę. Tikimės, kad ji Jus maloniai nustebins!</p>`
    },
    loyalty_points: {
        subject: "Jūs turite nepanaudotų taškų! 💰",
        body: `<h2 style="${styleH2}">Neiššvaistykite savo sukauptų taškų</h2>
<p style="${styleP}">Primename, kad už kiekvieną pirkinį Cukrinukas.lt parduotuvėje Jūs kaupiate lojalumo taškus. Šiuo metu savo sąskaitoje turite sukaupę apčiuopiamą taškų sumą, kurią galite panaudoti kaip nuolaidą!</p>
<p style="${styleP}">Prisijunkite prie savo paskyros, pažiūrėkite likutį ir iškeiskite taškus į nuolaidos kodą savo kitam užsakymui.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Mano taškai</a>
</div>`
    },
    double_points: {
        subject: "Dvigubų taškų savaitgalis prasideda! 📈",
        body: `<h2 style="${styleH2}">Kaupkite dvigubai greičiau!</h2>
<p style="${styleP}">Pasiilgote didelių nuolaidų? Šį savaitgalį už kiekvieną išleistą eurą gausite ne 1, o net 2 lojalumo taškus! Tai greičiausias būdas sukaupti nuolaidą ateities pirkiniams.</p>
<p style="${styleP}">Akcija galioja visoms prekėms be išimčių. Apsipirkite dabar ir stebėkite, kaip greitai auga Jūsų taškų balansas.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Pradėti rinkti taškus</a>
</div>`
    },
    points_expiring: {
        subject: "Dėmesio: Jūsų lojalumo taškai netrukus baigs galioti ⏳",
        body: `<h2 style="${styleH2}">Paskutinė proga panaudoti taškus</h2>
<p style="${styleP}">Draugiškas priminimas: Jūsų sukaupti lojalumo taškai baigs galioti lygiai po 7 dienų. Nenorime, kad jie tiesiog dingtų!</p>
<p style="${styleP}">Kviečiame prisijungti prie savo paskyros ir paversti šiuos taškus nuolaida prieš jiems anuliuojantis.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Išleisti taškus</a>
</div>`
    },
    referral: {
        subject: "Pakviesk draugą ir gauk dovanų! 🤝",
        body: `<h2 style="${styleH2}">Dalintis gera, o dar geriau – kai už tai atlyginama!</h2>
<p style="${styleP}">Ar žinojote, kad pakvietę draugą apsipirkti Cukrinukas.lt, abu būsite apdovanoti? Jūsų draugas gaus 5€ nuolaidą pirmajam pirkiniui, o kai jis apsipirks – Jūs taip pat gausite 5€ nuolaidą į savo sąskaitą!</p>
<p style="${styleP}">Nukopijuokite savo asmeninę nuorodą iš paskyros ir pasidalinkite ja su draugais, šeima ar kolegomis.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/account" style="${styleBtn}">Gauti pakvietimo nuorodą</a>
</div>`
    },
    miss_you_30: {
        subject: "Pasiilgome Jūsų! 🥺 Sugrįžkite skanėstų",
        body: `<h2 style="${styleH2}">Kur dingote?</h2>
<p style="${styleP}">Praėjo jau visas mėnuo, kai paskutinį kartą lankėtės pas mus. Pasiilgome Jūsų! Per tą laiką mūsų parduotuvę papildė daugybė naujų ir intriguojančių skonių.</p>
<p style="${styleP}">Sugrįžkite ir pasižvalgykite. Kadangi Jūsų labai pasiilgome, dovanojame nemokamą pristatymą Jūsų kitam užsakymui, įvedus kodą <b>SUGRIZK</b>.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Eiti į parduotuvę</a>
</div>`
    },
    miss_you_90: {
        subject: "Mums Jūsų trūksta! Štai 25% nuolaida sugrįžimui 💔",
        body: `<h2 style="${styleH2}">Nepaleidžiame taip lengvai!</h2>
<p style="${styleP}">Praėjo nemažai laiko nuo Jūsų paskutinio vizito. Suprantame, kad galbūt atradote kitus pomėgius, bet niekas nepranoksta gero šokolado ar guminukų kokybės, kurią mes siūlome.</p>
<p style="${styleP}">Norime Jums priminti, kodėl mus pasirinkote pirmąjį kartą. Prisijunkite prie mūsų su šia išskirtine sugrįžimo nuolaida:</p>
<div style="${styleBox}">
    <span style="${styleCode}">TRUKSTA25</span>
    <p style="margin-top:12px; font-size:14px; color:#64748b;">Nuolaida net 25% visam Jūsų krepšeliui.</p>
</div>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Atsiimti nuolaidą</a>
</div>`
    },
    collab_launch: {
        subject: "Nauja, išskirtinė partnerystė ir nematyti skoniai 🤝",
        body: `<h2 style="${styleH2}">Kai dvi jėgos susijungia...</h2>
<p style="${styleP}">Esame be galo laimingi galėdami pristatyti mūsų naujausią bendradarbiavimo projektą! Sujungėme jėgas su vienu žinomiausių vietinių kavos skrudintojų ir sukūrėme unikalią, kava kvepiančių saldumynų liniją.</p>
<p style="${styleP}">Ši kolekcija yra riboto leidimo ir bus prieinama tik tol, kol turėsime atsargų. Išbandykite tobulą kavos ir šokolado harmoniją.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/collab" style="${styleBtn}">Atrasti kolekciją</a>
</div>`
    },
    mystery_box: {
        subject: "Paslapčių dėžutė: ar išdrįsite išbandyti? 🎁",
        body: `<h2 style="${styleH2}">Mėgstate staigmenas?</h2>
<p style="${styleP}">Pristatome mūsų naujieną – Cukrinukas.lt MYSTERY BOX! Tai speciali dėžutė, kurios turinys yra griežtai saugoma paslaptis iki pat jos atidarymo momento.</p>
<p style="${styleP}">Mes garantuojame tik viena: dėžutėje esančių produktų vertė visada viršija dėžutės kainą, o viduje rasite pačius įvairiausius skonius, atkeliavusius iš skirtingų pasaulio kampelių.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/mystery" style="${styleBtn}">Noriu staigmenos!</a>
</div>`
    },
    free_shipping_weekend: {
        subject: "Siunčiame NEMOKAMAI visą savaitgalį! 🚚",
        body: `<h2 style="${styleH2}">Jokių pristatymo mokesčių!</h2>
<p style="${styleP}">Kas nemėgsta nemokamo pristatymo? Tik šį savaitgalį, visiems užsakymams nuo 10€, pristatymas į pasirinktą paštomatą visiškai nieko nekainuos!</p>
<p style="${styleP}">Pristatymo mokestis bus automatiškai nuskaičiuotas atsiskaitymo metu. Pildykite krepšelius ir leiskite mums pasirūpinti logistika.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Apsipirkti nemokamai</a>
</div>`
    },
    wholesale_invite: {
        subject: "Saldžios verslo dovanos ir didmenos pasiūlymai 📦",
        body: `<h2 style="${styleH2}">Ieškote dovanų įmonės darbuotojams?</h2>
<p style="${styleP}">Saldumynai – universali ir visada džiuginanti dovana. Nesvarbu, ar artėja įmonės gimtadienis, šventės, ar tiesiog norite pradžiuginti savo komandą – Cukrinukas.lt turi ką pasiūlyti!</p>
<p style="${styleP}">Kviečiame peržiūrėti mūsų didmenos/verslo katalogą, kuriame rasite specialias kainas perkant didesniais kiekiais ir galimybę personalizuoti pakuotes.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/b2b" style="${styleBtn}">Verslo pasiūlymai</a>
</div>`
    },
    bulk_buy: {
        subject: "Perkant daugiau – moki mažiau! ⚖️",
        body: `<h2 style="${styleH2}">Didelėms kompanijoms – didelės nuolaidos</h2>
<p style="${styleP}">Ruošiatės šventei, vakarėliui, ar tiesiog mėgstate pirkti atsargas ilgesniam laikui? Atnaujinome mūsų sistemą ir nuo šiol pritaikome automatines nuolaidas perkant didesnius kiekius.</p>
<p style="${styleP}">Pirkdami 3, 5 ar 10 vienetų tos pačios prekės, gausite iki 25% nuolaidą kiekvienam vienetui. Visą informaciją rasite prekių aprašymuose!</p>`
    },
    app_download: {
        subject: "Atsisiųskite programėlę – gaukite 10€! 📱",
        body: `<h2 style="${styleH2}">Cukrinukas jau ir Jūsų telefone!</h2>
<p style="${styleP}">Apsipirkti dar niekada nebuvo taip paprasta! Pristatome visiškai naują Cukrinukas.lt mobiliąją programėlę, skirtą iOS ir Android įrenginiams.</p>
<p style="${styleP}">Atsisiuntus programėlę ir prisijungus, Jūsų paskyroje automatiškai atsiras 10€ nuolaidos kuponas. Be to, programėlės naudotojai pirmieji gauna pranešimus apie žaibiškus išpardavimus!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/app" style="${styleBtn}">Atsisiųsti dabar</a>
</div>`
    },
    social_media_contest: {
        subject: "Dalyvaukite konkurse ir laimėkite metines atsargas! 📸",
        body: `<h2 style="${styleH2}">Mūsų didžiausias konkursas!</h2>
<p style="${styleP}">Kviečiame dalyvauti mūsų socialinių tinklų konkurse, kurio pagrindinis prizas – metinės saldumynų atsargos! (Tai reiškia pilną dėžę saldumynų kiekvieną mėnesį, visus metus).</p>
<p style="${styleP}">Taisyklės labai paprastos: nufotografuokite, kaip mėgaujatės mūsų produkcija, įkelkite į Instagram ar Facebook ir pažymėkite mus <b>@cukrinukas.lt</b>.</p>
<div style="text-align: center;">
    <a href="https://instagram.com/cukrinukas.lt" style="${styleBtn}">Eiti į Instagram</a>
</div>`
    },
    secret_sale: {
        subject: "🤫 Tss... Tik prenumeratoriams: Slaptas išpardavimas",
        body: `<h2 style="${styleH2}">Tai tik tarp mūsų!</h2>
<p style="${styleP}">Kadangi esate mūsų ištikimas naujienlaiškio prenumeratorius, turime Jums kai ką ypatingo. Šis išpardavimas nėra skelbiamas niekur kitur – nei mūsų puslapyje, nei socialiniuose tinkluose.</p>
<p style="${styleP}">Paspaudę žemiau esančią nuorodą, pateksite į slaptą prekių katalogą, kuriame visiems produktams taikoma didžiulė nuolaida. Nuoroda veiks tik 48 valandas.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/secret" style="${styleBtn}">Įeiti į slaptą zoną</a>
</div>`
    },
    survey: {
        subject: "Padėkite mums tobulėti ir gaukite dovaną 📝",
        body: `<h2 style="${styleH2}">Mums trūksta Jūsų nuomonės</h2>
<p style="${styleP}">Norime tapti geriausia saldumynų parduotuve Lietuvoje, bet be Jūsų pagalbos to nepadarysime. Kokių prekių pasigendate? Ką galėtume padaryti geriau?</p>
<p style="${styleP}">Prašome užpildyti trumpą 3 minučių apklausą. Kaip padėką, apklausos pabaigoje gausite unikalų 15% nuolaidos kodą savo kitam užsakymui.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/survey" style="${styleBtn}">Dalyvauti apklausoje</a>
</div>`
    },
    summer_sale: {
        subject: "Karštas vasaros išpardavimas! ☀️",
        body: `<h2 style="${styleH2}">Vasara, saulė ir... nuolaidos!</h2>
<p style="${styleP}">Atsigaivinkite geriausiais pasiūlymais. Vasaros prekių likučių išpardavimas jau prasidėjo. Ledinukai, vaisiniai guminukai ir lengvi užkandžiai iškyloms dabar pigiau!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Nerti į vasaros skonius</a>
</div>`
    },
    winter_sale: {
        subject: "Žiemos išpardavimas – jaukūs vakarai ❄️",
        body: `<h2 style="${styleH2}">Sušilkite su mūsų pasiūlymais</h2>
<p style="${styleP}">Ilgi ir šalti žiemos vakarai geriausi apsiklojus pledu, žiūrint filmą ir mėgaujantis karštu šokoladu bei zefyrais. Pasinaudokite specialiomis žiemos nuolaidomis ir susikurkite jaukią atmosferą namuose!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Žiemos jaukumas</a>
</div>`
    },

    // ==========================================
    // 📅 ŠVENTĖS IR PROGOS (55 - 75)
    // ==========================================
    birthday: {
        subject: "Su gimtadieniu! 🎂 Dovana Jums",
        body: `<div style="text-align: center;">
<h2 style="${styleH2}">Sveikiname su gimtadieniu! 🥳</h2>
<p style="${styleP}">Gimtadienis be saldumynų – ne gimtadienis! Šia ypatinga proga norime Jums padovanoti nedidelę staigmeną – <strong>nemokamą pristatymą ir 20% nuolaidą</strong> Jūsų šventiniam užsakymui.</p>
<div style="${styleBox}">
    <span style="${styleCode}">GIMTADIENIS20</span>
</div>
<p style="${styleP}">Linkime saldžių, džiugių ir nepamirštamų metų!</p>
<a href="https://cukrinukas.lt" style="${styleBtn}">Atsiimti dovaną</a>
</div>`
    },
    seasonal_christmas: {
        subject: "Jaukių ir saldžių Šv. Kalėdų! 🎄",
        body: `<div style="text-align: center;">
<h2 style="${styleH2}">Linksmų Šv. Kalėdų!</h2>
<p style="${styleP}">Tegul šios nuostabios šventės būna pripildytos artimųjų juoko, jaukios šilumos ir, žinoma, saldžių akimirkų.</p>
<p style="${styleP}">Dėkojame, kad šiais metais buvote kartu su mumis. Siunčiame Jums šventinę dovaną – Kalėdinę nuolaidą Jūsų šventiniam stalui ar dovanoms po eglute:</p>
<div style="${styleBox}">
    <span style="${styleCode}">KALEDOS2024</span>
</div>
<a href="https://cukrinukas.lt" style="${styleBtn}">Apsilankyti parduotuvėje</a>
</div>`
    },
    pre_christmas_prep: {
        subject: "Nepalikite Kalėdinių dovanų paskutinei minutei! 🎁",
        body: `<h2 style="${styleH2}">Saldžios dovanos be streso</h2>
<p style="${styleP}">Kalėdos artėja sparčiau nei manome! Išvenkite paskutinės minutės streso, eilių parduotuvėse ir siuntų vėlavimų. Paruošėme specialius dovanų rinkinius, kurie pradžiugins kiekvieną smaližių.</p>
<p style="${styleP}">Užsisakę dabar, būsite ramūs, kad dovanos atvyks laiku. Be to, visiems iš anksto paruoštiems rinkiniams taikome specialias kainas.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/gifts" style="${styleBtn}">Kalėdinės dovanos</a>
</div>`
    },
    new_year: {
        subject: "Saldžių ir sėkmingų Naujųjų Metų! 🎆",
        body: `<h2 style="${styleH2}">Pasitikime Naujus Metus saldžiai!</h2>
<p style="${styleP}">Seni metai skaičiuoja paskutines valandas. Norime Jums padėkoti už tai, kad buvote mūsų bendruomenės dalimi ir linkime, jog ateinantys metai būtų pilni džiaugsmo, naujų atradimų ir saldžių akimirkų!</p>
<p style="${styleP}">Tegul Naujųjų naktis būna įspūdinga!</p>`
    },
    seasonal_easter: {
        subject: "Su Šv. Velykomis! 🐣",
        body: `<h2 style="${styleH2}">Pavasariški sveikinimai!</h2>
<p style="${styleP}">Sveikiname Jus su pavasario ir atgimimo švente! Tegul margučių ridenimas būna linksmas, o stalas – gausus paties skaniausio šokolado.</p>
<p style="${styleP}">Velykų proga visiems šokoladiniams kiaušiniams ir zuikučiams taikome specialią šventinę nuolaidą!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/easter" style="${styleBtn}">Velykiniai pasiūlymai</a>
</div>`
    },
    seasonal_valentines: {
        subject: "Meilė tvyro ore... 💖",
        body: `<h2 style="${styleH2}">Saldūs linkėjimai Valentino proga!</h2>
<p style="${styleP}">Nustebinkite savo mylimą žmogų (arba tiesiog palepinkite save) saldžia dovana. Meilė yra saldi, kaip ir mūsų belgiškas šokoladas bei rožių formos guminukai.</p>
<p style="${styleP}">Užsisakykite dabar, kad siunta spėtų atkeliauti tiesiai Šv. Valentino dienai.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/valentines" style="${styleBtn}">Dovanos mylimiesiems</a>
</div>`
    },
    womens_day: {
        subject: "Su Kovo 8-ąja! 🌷",
        body: `<h2 style="${styleH2}">Žavingosios moterys,</h2>
<p style="${styleP}">Sveikiname Jus su Tarptautine moters diena! Linkime, kad kasdienybė būtų kupina pavasariškų spalvų, nuoširdžių šypsenų ir saldžių, Jus lepinančių akimirkų.</p>
<p style="${styleP}">Šia proga vietoje gėlių siūlome kai ką saldesnio – specialią nuolaidą visai parduotuvei:</p>
<div style="${styleBox}">
    <span style="${styleCode}">MOTERIMS10</span>
</div>`
    },
    mens_day: {
        subject: "Sveikinimai Vyro dienos proga! 🕶️",
        body: `<h2 style="${styleH2}">Stiprybės ir energijos!</h2>
<p style="${styleP}">Vyrai irgi mėgsta saldumynus! Sveikiname su Tarptautine vyro diena. Pasikraukite energijos su mūsų baltyminiais batonėliais, stipriu juoduoju šokoladu ar rūgščiais guminukais.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Vyriškas pasirinkimas</a>
</div>`
    },
    mothers_day: {
        subject: "Artėja Motinos diena – nustebinkite mamą! 🌸",
        body: `<h2 style="${styleH2}">Saldžiausia padėka Mamai</h2>
<p style="${styleP}">Motinos diena – puiki proga parodyti meilę ir dėkingumą pačiam svarbiausiam žmogui. Nustebinkite savo mamą išskirtinio skonio šokoladinių triufelių rinkiniu arba jos mėgstamais skanėstais.</p>
<p style="${styleP}">Paruošėme specialius, gražiai supakuotus rinkinius, paruoštus tiesiogiai įteikti.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/mothers-day" style="${styleBtn}">Dovanos Mamai</a>
</div>`
    },
    fathers_day: {
        subject: "Tėvo diena jau čia pat! 👔",
        body: `<h2 style="${styleH2}">Dovana geriausiam Tėčiui</h2>
<p style="${styleP}">Pamirškite kojines ar kaklaraiščius! Šiais metais padovanokite tėčiui tai, kas tikrai jį pradžiugins. Atrinkome populiariausius skanėstus tėčiams: nuo marcipanų iki čili pipirais pagardinto šokolado.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/fathers-day" style="${styleBtn}">Dovanos Tėčiui</a>
</div>`
    },
    childrens_day: {
        subject: "Vaikų gynimo diena – laikas dūkti! 🎈",
        body: `<h2 style="${styleH2}">Vaikystė turi būti saldi!</h2>
<p style="${styleP}">Sveikiname visus mažuosius smaližius ir jų tėvelius! Šiandien guminukams, ledinukams ir kramtomajai gumai taikome specialias, vaikiškai mažas kainas.</p>
<p style="${styleP}">Tegul šypsenos niekada nedingsta nuo vaikų veidų.</p>`
    },
    jonines: {
        subject: "Trumpos nakties linksmybės ir Joninių akcijos 🌿",
        body: `<h2 style="${styleH2}">Ieškome paparčio žiedo!</h2>
<p style="${styleP}">Trumpiausia metų naktis jau čia! Pasiruoškite Joninėms iš anksto ir aprūpinkite savo iškylų krepšius skaniausiais užkandžiais bei saldumynais prie laužo.</p>
<p style="${styleP}">Visiems Jonams ir Janinoms (bei visiems kitiems) taikome 15% nuolaidą su kodu <b>JONINES15</b>.</p>`
    },
    seasonal_halloween: {
        subject: "Pokštas ar saldainis? 🎃",
        body: `<h2 style="${styleH2}">Šiurpiausiai saldi naktis!</h2>
<p style="${styleP}">Helovinas jau čia! Pasiruoškite gąsdinti ir vaišinti, nes be saldainių ši naktis tiesiog neįmanoma. Tik šiandien – „baisiai“ geros kainos visiems saldainiams su Helovino tematika: guminėms akims, šikšnosparniams ir vorams!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/halloween" style="${styleBtn}">Noriu šiurpių saldainių!</a>
</div>`
    },
    singles_day: {
        subject: "Vienišių diena (11.11) – palepinkite save! 🛒",
        body: `<h2 style="${styleH2}">Ši diena priklauso Jums!</h2>
<p style="${styleP}">Švenčiame 11.11! Tai didžiausių išpardavimų diena, skirta meilei sau. Nereikia laukti, kol kas nors kitas padovanos saldumynų – palepinkite save jau dabar su didžiulėmis nuolaidomis!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt/11-11" style="${styleBtn}">Švęsti 11.11</a>
</div>`
    },
    black_friday: {
        subject: "⚫ BLACK FRIDAY prasideda dabar!",
        body: `<h2 style="${styleH2}; color:#000;">DIDŽIAUSIAS METŲ IŠPARDAVIMAS</h2>
<p style="${styleP}">Tai, ko laukėte visus metus. Cukrinukas.lt parduotuvėje kainos krenta į neregėtas žemumas. Nuolaidos asortimentui net iki <span style="color:#ef4444; font-weight:bold;">-50%</span>!</p>
<p style="${styleP}">Atsargos labai ribotos, o smaližių daug, todėl nelaukite rytojaus!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}; background-color:#000000; box-shadow:0 4px 10px rgba(0,0,0,0.3);">PIRKTI DABAR</a>
</div>`
    },
    cyber_monday: {
        subject: "💻 Cyber Monday: paskutinė proga!",
        body: `<h2 style="${styleH2}">Paskutinės išpardavimo valandos</h2>
<p style="${styleP}">Jei nespėjote visko įsigyti per Black Friday, Cyber Monday suteikia Jums antrą ir paskutinį šansą šiais metais. Negana to, šiandien dovanojame nemokamą pristatymą visiems, absoliučiai visiems užsakymams!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Paskutinis šansas</a>
</div>`
    },
    back_to_school: {
        subject: "Atgal į mokyklą su energija! 🎒",
        body: `<h2 style="${styleH2}">Pasiruošę mokslo metams?</h2>
<p style="${styleP}">Kad nauji mokslo metai eitųsi sklandžiau ir smagiau, reikia pasirūpinti skaniais užkandžiais ilgoms pertraukoms! Kuprinę ir sąsiuvinius jau turbūt turite, o štai skanėstais pasirūpinsime mes.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Mokyklinis krepšelis</a>
</div>`
    },
    teachers_day: {
        subject: "Mokytojų diena – atsidėkokite saldžiai 📚",
        body: `<h2 style="${styleH2}">Ačiū tiems, kurie moko</h2>
<p style="${styleP}">Artėja Mokytojų diena! Tai puiki proga ištarti „Ačiū“ pedagogams už jų kantrybę ir įdėtą darbą. Kokybiško šokolado plytelė ar elegantiškas saldainių rinkinys yra nepriekaištinga padėkos dovana.</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Dovanos mokytojams</a>
</div>`
    },
    boss_day: {
        subject: "Nustebinkite savo Bosą! 💼",
        body: `<h2 style="${styleH2}">Artėja Boso diena</h2>
<p style="${styleP}">Net ir griežčiausi vadovai turi silpnybę saldumynams! Parodykite dėmesį savo bosui ir įteikite prabangaus juodojo šokolado ar išskirtinių triufelių dėžutę. Gera nuotaika ofise garantuota!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Dovanos vadovams</a>
</div>`
    },
    graduation: {
        subject: "Išleistuvės! Švenčiame mokslo metų pabaigą 🎓",
        body: `<h2 style="${styleH2}">Sveikiname pasiekus finišą!</h2>
<p style="${styleP}">Egzaminai išlaikyti, mokslo metai baigti! Atėjo metas atšvęsti. Pasirūpinkite, kad išleistuvių šventė ar vakarėlis būtų pilnas saldžių staigmenų.</p>
<p style="${styleP}">Šventiniams užsakymams šią savaitę pritaikysime specialią 10% nuolaidą.</p>`
    },
    name_day: {
        subject: "Sveikiname Vardadienio proga! 🏷️",
        body: `<h2 style="${styleH2}">Gražios vardo dienos!</h2>
<p style="${styleP}">Mūsų stebuklingas kalendorius rodo, kad šiandien – Jūsų vardadienis! Ta proga siunčiame nuoširdžiausius sveikinimus ir norime Jus šiek tiek palepinti.</p>
<p style="${styleP}">Štai Jūsų asmeninė vardadienio dovana – 15% nuolaida viskam su kodu <b>VARDAS15</b>. Gražios Jums šventės!</p>
<div style="text-align: center;">
    <a href="https://cukrinukas.lt" style="${styleBtn}">Atsiimti dovaną</a>
</div>`
    }
};

document.getElementById('templateSelector').addEventListener('change', function() {
    const key = this.value;
    if (templates[key]) {
        // Nustatome temą
        document.getElementById('emailSubject').value = templates[key].subject;
        
        // Įdedame HTML į vizualų redaktorių
        document.getElementById('editor-visual').innerHTML = templates[key].body;
        
        // Atnaujiname paslėptą lauką
        syncContent();
    }
});
</script>

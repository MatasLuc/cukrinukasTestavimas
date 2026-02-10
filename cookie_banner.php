<style>
  #cookie-consent {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #ffffff;
    padding: 20px;
    box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
    display: none; /* Paslėpta pagal nutylėjimą */
    z-index: 9999;
    border-top: 1px solid #e4e7ec;
    font-family: 'Inter', sans-serif;
  }
  .cookie-content {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
  }
  .cookie-text {
    font-size: 14px;
    color: #475467;
    line-height: 1.5;
    flex: 1;
    min-width: 280px;
  }
  .cookie-buttons {
    display: flex;
    gap: 12px;
  }
  .cookie-btn {
    padding: 10px 18px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
  }
  .cookie-accept {
    background: #0b0b0b;
    color: #fff;
  }
  .cookie-accept:hover {
    background: #2563eb; /* Mėlyna, kaip jūsų dizaine */
  }
</style>

<div id="cookie-consent">
  <div class="cookie-content">
    <div class="cookie-text">
      <strong>Mes naudojame slapukus 🍪</strong><br>
      Siekdami užtikrinti geriausią patirtį, naudojame būtinuosius (krepšeliui) ir analitinius (statistikai) slapukus. 
      Tęsdami naršymą sutinkate su mūsų privatumo politika.
    </div>
    <div class="cookie-buttons">
      <button class="cookie-btn cookie-accept" onclick="acceptCookies()">Sutinku</button>
    </div>
  </div>
</div>

<script>
  function acceptCookies() {
    // Įrašome į naršyklę, kad vartotojas sutiko (galioja 30 dienų)
    const d = new Date();
    d.setTime(d.getTime() + (30*24*60*60*1000));
    let expires = "expires="+ d.toUTCString();
    document.cookie = "cookieConsent=true;" + expires + ";path=/";
    
    // Paslepiame juostą
    document.getElementById('cookie-consent').style.display = 'none';
  }

  // Patikriname, ar jau sutiko
  function checkCookieConsent() {
    let ca = document.cookie.split(';');
    let consent = false;
    for(let i = 0; i < ca.length; i++) {
      let c = ca[i];
      while (c.charAt(0) == ' ') c = c.substring(1);
      if (c.indexOf("cookieConsent=true") == 0) {
        consent = true;
      }
    }
    // Jei NĖRA sutikimo, rodome juostą
    if (!consent) {
      document.getElementById('cookie-consent').style.display = 'block';
    }
  }

  // Paleidžiame patikrinimą užsikrovus puslapiui
  window.addEventListener('load', checkCookieConsent);
</script>

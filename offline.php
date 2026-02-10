<?php
// Minimalus layout be duomenų bazės užklausų, kad veiktų be klaidų offline
?>
<!doctype html>
<html lang="lt">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nėra interneto | Cukrinukas</title>
  <style>
    body { margin: 0; font-family: 'Inter', sans-serif; background: #f7f7fb; color: #0b0b0b; display: flex; align-items: center; justify-content: center; height: 100vh; text-align: center; }
    .card { background: #fff; padding: 40px; border-radius: 24px; box-shadow: 0 12px 32px rgba(0,0,0,0.08); max-width: 400px; width: 90%; }
    h1 { margin: 0 0 12px; font-size: 24px; }
    p { color: #6b6b7a; margin-bottom: 24px; line-height: 1.5; }
    .btn { display: inline-block; padding: 12px 24px; background: #0b0b0b; color: #fff; text-decoration: none; border-radius: 12px; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Nėra interneto ryšio 📡</h1>
    <p>Atrodo, kad praradote ryšį. Galite naršyti puslapius, kuriuos jau lankėte anksčiau, arba bandykite atnaujinti.</p>
    <a href="/" class="btn">Bandyti dar kartą</a>
  </div>
</body>
</html>

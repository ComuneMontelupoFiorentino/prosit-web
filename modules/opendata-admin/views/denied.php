<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="<?php echo htmlspecialchars(LIZMAP_ASSETS_URL . 'favicon/favicon.ico', ENT_QUOTES); ?>">
<title>Accesso negato</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="<?php echo htmlspecialchars(ASSETS_URL . 'prosit.css', ENT_QUOTES); ?>">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
  <div class="alert alert-danger shadow-sm" style="max-width:480px;">
    <h5 class="alert-heading"><i class="icon-lock me-1"></i>Accesso negato</h5>
    <p class="mb-0"><?php echo htmlspecialchars($session['reason'], ENT_QUOTES); ?></p>
  </div>
</body>
</html>
<?php
/* Botão "Salvar na agenda" da mensagem de compra aprovada (WhatsApp).
   Página simples com 3 opções: Google Agenda, Outlook e iPhone/Apple.
   Outlook e iPhone/Apple usam o mesmo arquivo .ics (agenda.php?ics=1), que traz os dois encontros.
   Mudou data ou horário? Ajuste só a lista $ENCONTROS abaixo: o template da Meta não muda. */
header('X-Robots-Tag: noindex');

$TITULO = 'Imersão Âmbar · Rafael Fernandes';
$DESCRICAO = "Encontro ao vivo e online da Imersão Âmbar.\nO acesso chega pelo WhatsApp oficial da Âmbar Alternative.";
$LOCAL = 'Online (acesso pelo WhatsApp da Âmbar)';
/* horário de Brasília (UTC-3): início e fim de cada encontro */
$ENCONTROS = [
  ['2026-12-08 20:00', '2026-12-08 22:30'],
  ['2026-12-09 20:00', '2026-12-09 22:30'],
];

$tz = new DateTimeZone('America/Sao_Paulo');
$utc = function ($s) use ($tz) { return (new DateTime($s, $tz))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'); };

/* ---------- arquivo .ics (Outlook, iPhone e qualquer outro app de agenda) ---------- */
if (isset($_GET['ics'])) {
  $esc = function ($t) { return str_replace(["\\", ";", ",", "\n"], ["\\\\", "\\;", "\\,", "\\n"], $t); };
  $l = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Ambar Alternative//Imersao//PT-BR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
  foreach ($ENCONTROS as $i => $e) {
    array_push($l, 'BEGIN:VEVENT',
      'UID:imersao-ambar-' . ($i + 1) . '-' . $utc($e[0]) . '@ambaralternativewebinar.com.br',
      'DTSTAMP:' . gmdate('Ymd\THis\Z'),
      'DTSTART:' . $utc($e[0]), 'DTEND:' . $utc($e[1]),
      'SUMMARY:' . $esc($TITULO . ' (encontro ' . ($i + 1) . ' de ' . count($ENCONTROS) . ')'),
      'DESCRIPTION:' . $esc($DESCRICAO), 'LOCATION:' . $esc($LOCAL),
      'BEGIN:VALARM', 'TRIGGER:-PT30M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($TITULO . ' começa em 30 minutos'), 'END:VALARM',
      'END:VEVENT');
  }
  $l[] = 'END:VCALENDAR';
  header('Content-Type: text/calendar; charset=utf-8');
  header('Content-Disposition: inline; filename="imersao-ambar.ics"');
  header('Cache-Control: no-cache');
  echo implode("\r\n", $l) . "\r\n";
  exit;
}

/* ---------- Google Agenda: um evento que se repete nos dias seguintes ---------- */
$google = 'https://calendar.google.com/calendar/render?' . http_build_query([
  'action' => 'TEMPLATE',
  'text' => $TITULO,
  'dates' => $utc($ENCONTROS[0][0]) . '/' . $utc($ENCONTROS[0][1]),
  'details' => $DESCRICAO,
  'location' => $LOCAL,
  'ctz' => 'America/Sao_Paulo',
  'recur' => 'RRULE:FREQ=DAILY;COUNT=' . count($ENCONTROS),
], '', '&', PHP_QUERY_RFC3986);
$google = str_replace('%2F', '/', $google);   /* o Google espera a barra crua em dates=início/fim */
$ics = 'agenda.php?ics=1';

$dias = ['Terça, 8 de dezembro', 'Quarta, 9 de dezembro'];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Salvar na agenda · Imersão Âmbar</title>
<style>
  :root{--bg:#F4E7CF;--surface:#FAF2E2;--line:#DCC8A8;--ink:#1F140B;--text:#3A2A1A;--muted:#6B563F;--amber:#B05C0E;--amber-bright:#D9801A;--brown:#411F06}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px 16px}
  .card{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:28px 22px;text-align:center}
  .logo{font-family:"Cinzel",Georgia,serif;letter-spacing:.18em;font-size:22px;color:var(--brown)}
  .logo b{color:var(--amber-bright);font-weight:inherit}
  .sub{font-size:10px;letter-spacing:.32em;color:var(--amber);margin-top:4px}
  h1{font-size:20px;color:var(--ink);margin:22px 0 6px;font-weight:650}
  .datas{list-style:none;margin:14px 0 22px;font-size:14px;line-height:1.7;color:var(--muted)}
  .datas strong{color:var(--ink);font-weight:600}
  .btn{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:15px 16px;border-radius:12px;font-size:15px;font-weight:600;text-decoration:none;margin-bottom:10px;border:1.5px solid var(--line);background:#fff;color:var(--ink)}
  .btn:first-of-type{background:var(--amber-bright);border-color:var(--amber-bright);color:#1F140B}
  .btn svg{width:20px;height:20px;flex:0 0 auto}
  .nota{font-size:12px;color:var(--muted);margin-top:12px;line-height:1.5}
</style>
</head>
<body>
<main class="card">
  <div class="logo"><b>Â</b>MBAR</div>
  <div class="sub">ALTERNATIVE</div>
  <h1>Salve a Imersão na sua agenda</h1>
  <ul class="datas">
    <?php foreach ($ENCONTROS as $i => $e): ?>
      <li><strong><?= htmlspecialchars($dias[$i] ?? (new DateTime($e[0], $tz))->format('d/m')) ?></strong> · <?= (new DateTime($e[0], $tz))->format('H\hi') ?> às <?= (new DateTime($e[1], $tz))->format('H\hi') ?></li>
    <?php endforeach; ?>
    <li>horário de Brasília · online e ao vivo</li>
  </ul>
  <a class="btn" href="<?= htmlspecialchars($google) ?>" target="_blank" rel="noopener">
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 9h18M8 2v4M16 2v4" stroke="currentColor" stroke-width="2"/></svg>
    Google Agenda
  </a>
  <a class="btn" href="<?= htmlspecialchars($ics) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 7l9 6 9-6" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Outlook
  </a>
  <a class="btn" href="<?= htmlspecialchars($ics) ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 3c0 1.7-1.4 3.2-3 3.2-.2-1.6 1.3-3.2 3-3.2zM19.5 16.6c-.6 1.3-1.3 2.6-2.4 2.6s-1.4-.7-2.7-.7-1.6.7-2.7.7-1.9-1.4-2.6-2.6C7.6 14 7.4 10.9 8.6 9.3c.8-1.2 2-1.8 3.1-1.8s1.9.7 2.9.7 1.6-.7 3-.7c1 0 2 .5 2.7 1.4-2.4 1.3-2 4.8.2 5.7z" fill="currentColor"/></svg>
    iPhone / Apple
  </a>
  <p class="nota">Os dois encontros entram na agenda, com lembrete 30 minutos antes.</p>
</main>
</body>
</html>

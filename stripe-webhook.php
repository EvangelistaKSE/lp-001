<?php
/* Webhook da Stripe -> API de Conversões da Meta (evento Purchase).
   Configure na Stripe (Desenvolvedores > Webhooks) o endpoint
     https://ambaralternativewebinar.com.br/stripe-webhook.php
   com os eventos checkout.session.completed e checkout.session.async_payment_succeeded,
   e copie o segredo de assinatura (whsec_...) para "stripe_webhook_secret" em ~/.ambar_capi.json.

   - Só envia compras pagas (cartão: completed com payment_status "paid"; Pix/boleto: async_payment_succeeded).
   - event_id = client_reference_id (o mesmo "ref" usado como eventID pelo Pixel na página de obrigado),
     ou o id da sessão quando não houver ref.
   - Cada sessão é enviada uma única vez (marca em ~/.ambar_capi/enviados/). Se a Meta recusar,
     responde 500 para a Stripe tentar de novo mais tarde. */
require __DIR__ . '/capi-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responde($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responde(405, ['erro' => 'metodo']);

$c = capi_config();
$segredo = $c['stripe_webhook_secret'];
if ($segredo === '') { capi_log('ERRO webhook: sem stripe_webhook_secret na configuracao'); responde(500, ['erro' => 'configuracao']); }

$body = (string)file_get_contents('php://input');

/* ---------- assinatura da Stripe: t=...,v1=...[,v1=...] ---------- */
$t = null; $assinaturas = [];
foreach (explode(',', (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '')) as $parte) {
  $kv = explode('=', trim($parte), 2);
  if (count($kv) !== 2) continue;
  if ($kv[0] === 't' && ctype_digit($kv[1])) $t = (int)$kv[1];
  elseif ($kv[0] === 'v1') $assinaturas[] = $kv[1];
}
$valida = false;
if ($t !== null && $assinaturas && abs(time() - $t) <= 300) {
  $esperada = hash_hmac('sha256', $t . '.' . $body, $segredo);
  foreach ($assinaturas as $a) if (hash_equals($esperada, $a)) { $valida = true; break; }
}
if (!$valida) { capi_log('ERRO webhook: assinatura invalida ou expirada'); responde(400, ['erro' => 'assinatura']); }

/* ---------- evento ---------- */
$ev = json_decode($body, true);
if (!is_array($ev)) responde(400, ['erro' => 'json']);
$tipo = (string)($ev['type'] ?? '');
$s = $ev['data']['object'] ?? null;
if (!is_array($s)) responde(200, ['ignorado' => true]);

$pago = ($tipo === 'checkout.session.completed' && ($s['payment_status'] ?? '') === 'paid')
     || $tipo === 'checkout.session.async_payment_succeeded';
if (!$pago) responde(200, ['ignorado' => true]);

$sid = (string)($s['id'] ?? '');
if ($sid === '') responde(400, ['erro' => 'sessao']);
$marcaNome = preg_match('/^[A-Za-z0-9_]{1,200}$/', $sid) ? $sid : sha1($sid);
$marca = capi_dir('enviados') . '/' . $marcaNome;

/* trava por sessão: evita envio duplo se a Stripe repetir a notificação ao mesmo tempo */
$trava = @fopen($marca . '.lock', 'c');
if ($trava) flock($trava, LOCK_EX);
function libera() { global $trava, $marca; if ($trava) { flock($trava, LOCK_UN); fclose($trava); @unlink($marca . '.lock'); } }

if (is_file($marca)) { libera(); responde(200, ['ok' => true, 'repetido' => true]); }

$ref = capi_valid_ref($s['client_reference_id'] ?? null) ? $s['client_reference_id'] : null;
$ctx = $ref ? capi_ctx_load($ref) : [];
$cd = is_array($s['customer_details'] ?? null) ? $s['customer_details'] : [];

/* dados pessoais: Stripe primeiro (e-mail/telefone do pagamento), formulário da LP como reserva */
$pii = [
  'email' => ($cd['email'] ?? '') ?: ($ctx['email'] ?? ''),
  'tel' => ($cd['phone'] ?? '') ?: ($ctx['tel'] ?? ''),
];
if (!empty($ctx['nome']) || !empty($ctx['sobrenome'])) {
  $pii['nome'] = $ctx['nome'] ?? '';
  $pii['sobrenome'] = $ctx['sobrenome'] ?? '';
} else {
  list($pii['nome'], $pii['sobrenome']) = capi_split_name($cd['name'] ?? '');
}

$centavos = (int)($s['amount_total'] ?? 0);
$produtos = [
  4790 => 'Imersao Ambar - Ingresso',
  5790 => 'Imersao Ambar - Ingresso + Comunidade',
  8990 => 'Aula bonus domingo',
  6293 => 'Aula bonus domingo',
  7641 => 'Aula bonus domingo',
];

$evento = [
  'event_name' => 'Purchase',
  'event_time' => (int)($ev['created'] ?? 0) ?: time(),
  'event_id' => $ref ?: $sid,
  'action_source' => 'website',
  'event_source_url' => ($ctx['url'] ?? '') ?: $c['site_url'],
  'user_data' => capi_user_data($pii, $ctx),
  'custom_data' => [
    'value' => $centavos / 100,
    'currency' => strtoupper((string)(($s['currency'] ?? '') ?: 'brl')),
    'content_name' => $produtos[$centavos] ?? 'Compra Ambar',
  ],
];

if (!capi_send($evento, 'stripe')) { libera(); responde(500, ['erro' => 'meta']); }

@file_put_contents($marca, date('c') . "\n", LOCK_EX);
libera();
responde(200, ['ok' => true]);

<?php
/* API de Conversões da Meta: recebe eventos do próprio site.
   POST JSON {acao, ref, url, ...}
     acao "lead"     -> formulário da LP: guarda o contexto do visitante e envia o Lead (event_id = ref)
     acao "checkout" -> botões de pagamento sem formulário (bônus de sábado): só guarda o contexto
   O "ref" também vai para a Stripe como client_reference_id; o webhook usa esse contexto
   para enviar a compra (Purchase) com os dados de quem clicou. */
require __DIR__ . '/capi-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sai($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sai(405, ['erro' => 'metodo']);

/* só aceita chamadas do próprio site */
$c = capi_config();
$orig = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = strtolower((string)parse_url($orig, PHP_URL_HOST) . (parse_url($orig, PHP_URL_PORT) ? ':' . parse_url($orig, PHP_URL_PORT) : ''));
if (!in_array($host, $c['allowed_hosts'], true)) sai(403, ['erro' => 'origem']);

/* limite simples: 20 chamadas a cada 10 minutos por IP */
/* (usa o IP real da conexão: o X-Forwarded-For pode ser forjado para escapar do limite) */
$ctx = capi_request_context();
if (!capi_limite('', 20, 600)) sai(429, ['erro' => 'limite']);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8000), true);
if (!is_array($in)) sai(400, ['erro' => 'json']);
$acao = $in['acao'] ?? '';
$ref = $in['ref'] ?? '';
if (!capi_valid_ref($ref) || !in_array($acao, ['lead', 'checkout'], true)) sai(400, ['erro' => 'dados']);

$url = is_string($in['url'] ?? null) ? substr($in['url'], 0, 2048) : '';
$uh = strtolower((string)parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : ''));
if (!in_array($uh, $c['allowed_hosts'], true) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) $url = $c['site_url'];

$pii = [];
if ($acao === 'lead') {
  $pii = [
    'email' => substr(is_scalar($in['email'] ?? '') ? (string)($in['email'] ?? '') : '', 0, 200),
    'tel' => substr(is_scalar($in['tel'] ?? '') ? (string)($in['tel'] ?? '') : '', 0, 40),
    'nome' => substr(is_scalar($in['nome'] ?? '') ? (string)($in['nome'] ?? '') : '', 0, 80),
    'sobrenome' => substr(is_scalar($in['sobrenome'] ?? '') ? (string)($in['sobrenome'] ?? '') : '', 0, 80),
  ];
  if (!capi_norm_email($pii['email'])) sai(400, ['erro' => 'email']);
}

if (capi_ctx_save($ref, $ctx + ['url' => $url, 'ts' => time(), 'acao' => $acao] + $pii) === false) sai(409, ['erro' => 'ref']);

if ($acao === 'lead') {
  $ok = capi_send([
    'event_name' => 'Lead',
    'event_time' => time(),
    'event_id' => $ref,
    'action_source' => 'website',
    'event_source_url' => $url,
    'user_data' => capi_user_data($pii, $ctx),
    'custom_data' => ['content_name' => 'Imersao Ambar'],
  ], 'site');
  sai(200, ['ok' => $ok]);
}
sai(200, ['ok' => true]);

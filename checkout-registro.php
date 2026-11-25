<?php
/* Registra a ida ao checkout (formulário da LP), para a automação de WhatsApp:
   - pagou      -> mensagem de compra aprovada na hora (stripe-compra-....php)
   - não pagou  -> mensagem de abandono depois de alguns minutos (checkout-abandono.php, no cron)
   POST JSON {ref, plano, nome, email, tel}
   Responde {ok, codigo}. O código curto vai no botão "Vou finalizar a inscrição" (finalizar.php?r=codigo).
   Funciona também no modo equipe/teste da LP, para a equipe conseguir testar as mensagens. */
require __DIR__ . '/capi-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sai($code, $arr) { http_response_code($code); echo json_encode($arr); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sai(405, ['erro' => 'metodo']);

/* só aceita chamadas do próprio site */
$c = capi_config();
$orig = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = strtolower((string)parse_url($orig, PHP_URL_HOST) . (parse_url($orig, PHP_URL_PORT) ? ':' . parse_url($orig, PHP_URL_PORT) : ''));
if (!in_array($host, $c['allowed_hosts'], true)) sai(403, ['erro' => 'origem']);

/* limite simples: 10 registros a cada 10 minutos por IP (IP real da conexão) */
if (!capi_limite('ck_', 10, 600)) sai(429, ['erro' => 'limite']);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 4000), true);
if (!is_array($in)) sai(400, ['erro' => 'json']);
$txt = function ($k, $max) use ($in) { return zoho_texto($in[$k] ?? '', $max); };

$ref   = $in['ref'] ?? '';
$plano = $in['plano'] ?? '';
$nome  = $txt('nome', 120);
$email = capi_norm_email($txt('email', 200));
$tel   = capi_norm_phone($txt('tel', 40));
$planos = checkout_planos();
if (!capi_valid_ref($ref) || !isset($planos[$plano])) sai(400, ['erro' => 'dados']);
if ($nome === '' || $email === '' || $tel === '') sai(400, ['erro' => 'dados']);

/* um ref nunca é reaproveitado */
if (is_file(checkout_arquivo($ref))) sai(409, ['erro' => 'ref']);
$cod = checkout_novo_codigo();
if ($cod === null) sai(500, ['erro' => 'codigo']);

$p = $planos[$plano];
$url = $p['url'] . '?prefilled_email=' . rawurlencode($email) . '&client_reference_id=' . rawurlencode($ref);

$fh = @fopen(checkout_arquivo($ref), 'x');
if (!$fh) sai(409, ['erro' => 'ref']);
fwrite($fh, json_encode([
  'ref' => $ref, 'codigo' => $cod, 'ts' => time(),
  'nome' => $nome, 'email' => $email, 'tel' => $tel,
  'plano' => $plano, 'plano_nome' => $p['nome'], 'checkout_url' => $url,
  'teste' => !empty($in['teste']),
  'status' => 'aberto',            /* aberto -> comprou */
  'abandono' => null,              /* {ts, ok, detalhe} depois do envio */
  'compra' => null,                /* {ts, ok, detalhe} depois do envio */
  'cliques' => [],
], CAPI_JSON));
fclose($fh);
@file_put_contents(capi_dir('checkout_cod') . '/' . $cod, $ref, LOCK_EX);
capi_cleanup();

sai(200, ['ok' => true, 'codigo' => $cod]);

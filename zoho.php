<?php
/* Formulários do site -> Zoho CRM (módulo "Webinar - Alavancagem em 2027"), via Zoho Flow.
   POST JSON {origem, ...campos}
     origem "lp"           -> formulário de compra da LP (status "Iniciou a compra")
     origem "chat"         -> chat da LP (pediu atendimento)
     origem "qualificacao" -> formulário de qualificação (/qualificacao/ e páginas de obrigado)
   Cada origem só pode mandar os seus campos; o resto é descartado aqui no servidor. */
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
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) sai(415, ['erro' => 'tipo']);

/* limite simples: 15 envios a cada 10 minutos por IP (IP real da conexão) */
if (!capi_limite('z_', 15, 600)) sai(429, ['erro' => 'limite']);

$raw = (string)file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode($raw, true);
if (!is_array($in)) sai(400, ['erro' => 'json']);
if (!empty($in['site'])) sai(200, ['ok' => true]);          /* armadilha para robôs (campo escondido) */

$CAMPOS = [
  'lp'           => ['nome_completo', 'celular', 'email', 'ingresso'],
  'chat'         => ['nome_completo', 'celular', 'duvida_chat'],
  'qualificacao' => ['nome_completo', 'celular', 'email', 'objetivo', 'tem_imoveis', 'usou_credito', 'credito_tipos',
                     'disponivel', 'quando', 'aporte', 'profissao', 'cidade_estado', 'pergunta_rafael'],
];
$origem = $in['origem'] ?? '';
if (!is_string($origem) || !isset($CAMPOS[$origem])) sai(400, ['erro' => 'origem']);

$d = [];
foreach ($CAMPOS[$origem] as $k) if (isset($in[$k]) && is_scalar($in[$k])) $d[$k] = (string)$in[$k];

if (zoho_texto($d['nome_completo'] ?? '', 120) === '' || capi_norm_phone($d['celular'] ?? '') === '') sai(400, ['erro' => 'dados']);

if ($origem === 'lp') {
  if (!in_array($d['ingresso'] ?? '', ['Básico', 'Plus'], true)) unset($d['ingresso']);
  if (capi_norm_email($d['email'] ?? '') === '') sai(400, ['erro' => 'email']);
  $d['status'] = 'Iniciou a compra';
  $d['etapa'] = 'Página Acessada';     /* fase 1: o Flow só aplica esta etapa se o card ainda não tiver etapa */
}
if ($origem === 'qualificacao') {
  if (($in['autorizacao'] ?? '') !== 'Sim') sai(400, ['erro' => 'autorizacao']);
  $d['autorizacao'] = 'Sim';
}

sai(200, ['ok' => zoho_send($d, $origem)]);

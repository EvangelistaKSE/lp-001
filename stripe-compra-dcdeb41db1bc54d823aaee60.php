<?php
/* Recebe da Stripe as compras aprovadas do webinar (webhook) e grava o comprador no
   Zoho CRM pelo formulário web "Webinar 27 28 compras Stripe" (etiqueta "Comprador webinar 27-28").
   Eventos: checkout.session.completed (pago) e checkout.session.async_payment_succeeded (Pix).
   Só aceita os 3 links de pagamento do webinar e ignora reenvios da mesma compra.
   O CRM calcula sozinho "Ingresso comprado" e "Valor pago (R$)" a partir do valor em centavos. */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

$LINKS = [
  'plink_1UcpXaA7WMEJpu3N5BpyvCSp' => 'Webinar · Ingresso (R$ 47,90)',
  'plink_1UctdrA7WMEJpu3N8vYPxQoG' => 'Webinar · Ingresso + Comunidade (R$ 57,90)',
  'plink_1UcpYMA7WMEJpu3NsHP5X8nc' => 'Aula bônus domingo',
];
$ZOHO = [
  'url'        => 'https://crm.zoho.com/crm/WebToLeadForm',
  'xnQsjsdp'   => '7a1dc0a450200081cc55878cdcda65e6fd07000a5b20b6b4db18325e96b2b6c3',
  'xmIwtLD'    => '7bda6372dcb09145b0a97ec11bb4adb72fa6fb24287b9d99e08bba3b291d6000122dd29c9682ca1cf29c494d8270ed62',
  'actionType' => 'TGVhZHM=',
  'returnURL'  => 'https://ambaralternativewebinar.com.br/',
];

function fim($cod, $msg) { http_response_code($cod); echo json_encode(['ok' => $cod < 300, 'msg' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fim(405, 'use POST');
$ev = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($ev) || empty($ev['type']) || empty($ev['data']['object'])) fim(400, 'evento inválido');

$tipo = $ev['type'];
$s = $ev['data']['object'];
if (!in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) fim(200, 'ignorado: ' . $tipo);
if (($s['payment_status'] ?? '') !== 'paid') fim(200, 'ignorado: ainda não pago');
$link = $s['payment_link'] ?? '';
if (!isset($LINKS[$link])) fim(200, 'ignorado: outro link');

/* não grava a mesma compra duas vezes (a Stripe reenvia em caso de falha) */
$home = dirname(__DIR__, 2);
$reg  = $home . '/.stripe_compras_webinar.log';
$sid  = preg_replace('/[^A-Za-z0-9_]/', '', $s['id'] ?? '');
if ($sid === '') fim(400, 'sem id');
$ja = is_file($reg) ? (string)file_get_contents($reg) : '';
if (strpos($ja, $sid . "\n") !== false) fim(200, 'já registrado');

$cd    = $s['customer_details'] ?? [];
$nome  = trim((string)($cd['name'] ?? '')) ?: 'Sem nome';
$email = trim((string)($cd['email'] ?? ($s['customer_email'] ?? '')));
$tel   = preg_replace('/\D/', '', (string)($cd['phone'] ?? ''));
$cent  = (int)($s['amount_total'] ?? 0);

$campos = [
  'xnQsjsdp' => $ZOHO['xnQsjsdp'], 'zc_gad' => '', 'xmIwtLD' => $ZOHO['xmIwtLD'],
  'actionType' => $ZOHO['actionType'], 'returnURL' => $ZOHO['returnURL'],
  'Last Name' => mb_substr($nome, 0, 80), 'Email' => $email, 'Mobile' => $tel,
  'LEADCF20' => $link, 'LEADCF55' => (string)$cent,
  'Lead Status' => 'Aguardando Contato',
  'Description' => 'Compra Stripe · ' . $LINKS[$link] . ' · R$ ' . number_format($cent / 100, 2, ',', '.') . ' · ' . date('d/m/Y H:i') . ' · sessão ' . $sid,
  'aG9uZXlwb3Q' => '',
];
$ch = curl_init($ZOHO['url']);
curl_setopt_array($ch, [
  CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos),
  CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
]);
curl_exec($ch);
$cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($cod < 200 || $cod >= 400) fim(502, 'zoho respondeu ' . $cod);

@file_put_contents($reg, $sid . "\n", FILE_APPEND | LOCK_EX);
fim(200, 'gravado');

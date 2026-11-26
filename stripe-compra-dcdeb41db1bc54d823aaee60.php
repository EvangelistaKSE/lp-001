<?php
/* Recebe da Stripe as compras aprovadas do webinar (webhook) e grava o comprador no
   Zoho CRM, módulo "Webinar - Alavancagem em 2027", pelo Zoho Flow (zoho_send em capi-lib.php).
   O Flow cria ou atualiza o registro pelo Celular: quem preencheu a LP e depois pagou fica num registro só.
   Eventos: checkout.session.completed (pago) e checkout.session.async_payment_succeeded (Pix).
   Só aceita os 3 links de pagamento do webinar e ignora reenvios da mesma compra. */
require __DIR__ . '/capi-lib.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('America/Sao_Paulo');

/* link de pagamento => [ingresso no CRM, status no CRM] */
$LINKS = [
  'plink_1UcpXaA7WMEJpu3N5BpyvCSp' => ['Básico', 'Comprou'],
  'plink_1UctdrA7WMEJpu3N8vYPxQoG' => ['Plus', 'Comprou'],
  'plink_1UcpYMA7WMEJpu3NsHP5X8nc' => ['', 'Comprou aula de domingo'],
  // Links novos (26/11): ingresso R$89,90, ingresso+comunidade R$97,90, aula bonus R$47,90
  'plink_1UfueCA7WMEJpu3N19H8Lbwk' => ['Básico', 'Comprou'],
  'plink_1UfuMnA7WMEJpu3NSH8KiaCM' => ['Plus', 'Comprou'],
  'plink_1UfuiEA7WMEJpu3NOgf7adBZ' => ['', 'Comprou aula de domingo'],
];

function fim($cod, $msg) { http_response_code($cod); echo json_encode(['ok' => $cod < 300, 'msg' => $msg]); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fim(405, 'use POST');
/* só aceita eventos assinados pela Stripe (segredo do endpoint em ~/.ambar_capi.json, chave "stripe_compra_secret") */
$body = (string)file_get_contents('php://input');
$segredo = trim((string)(capi_config()['stripe_compra_secret'] ?? ''));
if ($segredo === '') { capi_log('ERRO compra: sem stripe_compra_secret na configuracao'); fim(500, 'configuracao'); }
if (!stripe_assinatura_ok($body, $segredo)) { capi_log('ERRO compra: assinatura invalida ou expirada'); fim(400, 'assinatura'); }
$ev = json_decode($body, true);
if (!is_array($ev) || !is_string($ev['type'] ?? null) || !is_array($ev['data']['object'] ?? null)) fim(400, 'evento inválido');

$tipo = $ev['type'];
$s = $ev['data']['object'];
if (!in_array($tipo, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) fim(200, 'ignorado: ' . $tipo);
if (($s['payment_status'] ?? '') !== 'paid') fim(200, 'ignorado: ainda não pago');
$link = $s['payment_link'] ?? '';
if (!isset($LINKS[$link])) fim(200, 'ignorado: outro link');

/* não grava a mesma compra duas vezes (a Stripe reenvia em caso de falha) */
$sid  = preg_replace('/[^A-Za-z0-9_]/', '', is_string($s['id'] ?? null) ? $s['id'] : '');
if ($sid === '') fim(400, 'sem id');
$marca = capi_dir('compras') . '/' . $sid;
if (is_file($marca)) fim(200, 'já registrado');

$cd    = is_array($s['customer_details'] ?? null) ? $s['customer_details'] : [];
$str   = function ($v) { return is_scalar($v) ? trim((string)$v) : ''; };
$nome  = $str($cd['name'] ?? '');
$email = $str($cd['email'] ?? ($s['customer_email'] ?? ''));
$tel   = $str($cd['phone'] ?? '');
$cent  = (int)($s['amount_total'] ?? 0);

/* dados que a pessoa digitou na LP (guardados pelo capi.php com o mesmo ref que foi para a Stripe) */
$ref = capi_valid_ref($s['client_reference_id'] ?? null) ? $s['client_reference_id'] : null;
$lp  = $ref ? capi_ctx_load($ref) : [];

[$ingresso, $status] = $LINKS[$link];
/* A compra atualiza o MESMO registro criado pelo formulário da LP: a chave é o celular digitado na LP
   (achado pelo ref que foi para a Stripe). Nome e e-mail vão iguais aos digitados na LP (não mudam o registro;
   o Nome Completo é obrigatório no módulo). Os dados de quem pagou vão nos campos "comprador_*", que só a Stripe preenche.
   Sem o ref da LP (link de pagamento aberto direto), o registro usa os dados da Stripe. */
$lpTel = capi_norm_phone((string)($lp['tel'] ?? ''));
if ($lpTel !== '') {
  $lpNome = trim((string)($lp['nome'] ?? '') . ' ' . (string)($lp['sobrenome'] ?? ''));
  $dados = ['nome_completo' => $lpNome !== '' ? $lpNome : ($nome !== '' ? $nome : 'Sem nome'), 'celular' => $lpTel, 'email' => (string)($lp['email'] ?? '')];
} else {
  $dados = ['nome_completo' => $nome !== '' ? $nome : 'Sem nome', 'celular' => $tel, 'email' => $email];
}
$dados += [
  'status'             => $status,
  'comprador_nome'     => $nome,
  'comprador_email'    => $email,
  'comprador_telefone' => $tel,
];
if ($ingresso !== '') {
  $dados['ingresso'] = $ingresso;
  $dados['etapa'] = 'Ingresso Comprado';   /* fase 2: avança o card de "Página Acessada" para "Ingresso Comprado" */
  $dados['valor_ingresso'] = number_format($cent / 100, 2, '.', '');
  $dados['link_pagamento'] = $link;
}
/* ---------- WhatsApp: mensagem de compra aprovada, na hora (uma vez por sessão de pagamento) ----------
   Marca a ida ao checkout como "comprou" (o cron não manda o abandono) e o celular como comprador. */
$ck = $ref ? checkout_load($ref) : null;
if ($ck) checkout_update($ref, function ($x) { if (!$x) return null; $x['status'] = 'comprou'; $x['comprou_ts'] = time(); return $x; });
$waTel  = capi_norm_phone((string)($ck['tel'] ?? '')) ?: ($lpTel !== '' ? $lpTel : capi_norm_phone($tel));
$waNome = trim((string)($ck['nome'] ?? '')) ?: ($dados['nome_completo'] !== 'Sem nome' ? $dados['nome_completo'] : '');
if ($waTel !== '' && ($m = comprou_tel_marca($waTel))) @touch($m);
$waPlano = null;
foreach (checkout_planos() as $k => $p) if ($p['link'] === $link) $waPlano = $k;
$waMarca = capi_dir('wa_obrigado') . '/' . $sid;
$waFalhou = false;
if ($waPlano && $waTel !== '' && !is_file($waMarca)) {
  $w = wa_config();
  $tpl = $waPlano === 'comunidade' ? $w['wa_tpl_compra_comunidade'] : $w['wa_tpl_compra_ingresso'];
  if ($tpl === '') capi_log("WA compra: sem template para o plano $waPlano, nao enviado");
  elseif (@fopen($waMarca, 'x')) {       /* reserva: reenvio da Stripe ao mesmo tempo não duplica */
    $primeiro = capi_split_name($waNome)[0] ?: 'tudo bem';
    [$ok, $det] = wa_enviar_template($waTel, $waNome, $tpl, [$primeiro], null, 'compra');
    if (!$ok) {                          /* falhou: pede para a Stripe reenviar o evento (até 3 vezes) */
      @unlink($waMarca);
      $tent = capi_dir('wa_obrigado') . '/' . $sid . '.tentativas';
      $n = (int)@file_get_contents($tent) + 1;
      @file_put_contents($tent, (string)$n);
      $waFalhou = $n < 3;
    }
    if ($ck) checkout_update($ref, function ($x) use ($ok, $det) { if (!$x) return null; $x['compra'] = ['ts' => time(), 'ok' => $ok, 'detalhe' => $det]; return $x; });
  }
}

/* sem celular nao ha como achar/criar o registro (o Celular e a chave); nao adianta a Stripe reenviar */
if (capi_norm_phone($dados['celular']) === '') { capi_log("ZOHO compra sem celular, sessao $sid"); fim(200, 'sem celular'); }
if (!zoho_send($dados, 'compra')) fim(502, 'zoho nao gravou');

if ($waFalhou) fim(502, 'whatsapp nao enviado');
@touch($marca);
fim(200, 'gravado');

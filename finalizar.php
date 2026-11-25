<?php
/* Botão "Vou finalizar a inscrição" da mensagem de abandono: finalizar.php?r=<código de 8 caracteres>
   Redireciona na hora para o checkout da modalidade que a pessoa escolheu (com o e-mail já preenchido)
   e registra o clique. Código desconhecido ou compra já feita -> volta para a LP. */
require __DIR__ . '/capi-lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$c = capi_config();
$voltar = rtrim($c['site_url'], '/') . '/#oferta';
$cod = $_GET['r'] ?? '';
$ref = checkout_ref_por_codigo($cod);
$destino = $voltar;

if ($ref) {
  $d = checkout_update($ref, function ($d) {
    if (!$d) return null;
    $d['cliques'] = array_slice(array_merge((array)($d['cliques'] ?? []), [time()]), -20);
    return $d;
  });
  if ($d && ($d['status'] ?? '') !== 'comprou' && strpos((string)($d['checkout_url'] ?? ''), 'https://buy.stripe.com/') === 0) {
    $destino = $d['checkout_url'];
  }
  capi_log('CLIQUE finalizar ' . ($destino === $voltar ? 'lp' : 'checkout'));
}

header('Location: ' . $destino, true, 302);
exit;

<?php
/* Quantos usos restam do cupom BONUSSABADO30 (bônus de sábado).
   A chave de leitura da Stripe fica FORA do public_html, no arquivo
   .stripe_cupons_key, na pasta inicial da conta (home).
   A página só recebe {"restam": N, "max": 10}. Resposta guardada por 20 s. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$home  = dirname(__DIR__, 2);
$chave = @trim((string)@file_get_contents($home . '/.stripe_cupons_key'));
$cache = sys_get_temp_dir() . '/ambar_cupom_bonus30.json';

if (is_file($cache) && time() - filemtime($cache) < 20) { readfile($cache); exit; }
if ($chave === '') { http_response_code(503); echo '{"erro":"sem chave"}'; exit; }

$url = 'https://api.stripe.com/v1/promotion_codes?code=BONUSSABADO30&limit=1';
$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_USERPWD => $chave . ':',
  CURLOPT_TIMEOUT => 6,
]);
$res = curl_exec($ch);
$cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$d = json_decode((string)$res, true);
if ($cod !== 200 || empty($d['data'][0])) { http_response_code(502); echo '{"erro":"stripe"}'; exit; }

$p     = $d['data'][0];
$c     = (isset($p['coupon']) && is_array($p['coupon'])) ? $p['coupon'] : [];
if (!empty($p['max_redemptions'])) {            /* limite no código promocional */
  $max = (int)$p['max_redemptions']; $usos = (int)($p['times_redeemed'] ?? 0);
} elseif (!empty($c['max_redemptions'])) {      /* limite no cupom */
  $max = (int)$c['max_redemptions']; $usos = (int)($c['times_redeemed'] ?? 0);
} else { $max = 10; $usos = (int)($p['times_redeemed'] ?? 0); }
$ativo = !empty($p['active']) && ($c === [] || !empty($c['valid']) || !isset($c['valid']));
$out = json_encode(['restam' => $ativo ? max(0, $max - $usos) : 0, 'max' => $max]);
@file_put_contents($cache, $out);
echo $out;

<?php
/* API de Conversões da Meta: funções comuns a capi.php e stripe-webhook.php.
   Configuração e dados ficam FORA do public_html, na pasta inicial da conta:
     ~/.ambar_capi.json   -> chaves (token da Meta e segredo do webhook da Stripe)
     ~/.ambar_capi/       -> contexto dos visitantes, controle de envios e log
   Este arquivo não faz nada se for aberto diretamente no navegador. */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(404); exit; }

/* JSON sem escapar barras/acentos; UTF-8 inválido é substituído em vez de fazer o json_encode falhar */
define('CAPI_JSON', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

function capi_home() {
  $h = getenv('AMBAR_CAPI_HOME');               /* só para testes locais */
  return $h ? rtrim($h, '/\\') : dirname(__DIR__, 2);
}

function capi_config() {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $raw = @file_get_contents(capi_home() . '/.ambar_capi.json');
  $c = json_decode((string)$raw, true);
  if (!is_array($c)) $c = [];
  $cfg = $c + [
    'meta_token' => '',
    'stripe_webhook_secret' => '',
    'pixel_id' => '1648412173302853',
    'graph_version' => 'v23.0',
    'graph_base' => 'https://graph.facebook.com',
    'test_event_code' => '',
    'dry_run' => false,
    'allowed_hosts' => ['ambaralternativewebinar.com.br', 'www.ambaralternativewebinar.com.br'],
    'site_url' => 'https://ambaralternativewebinar.com.br/',
  ];
  foreach (['meta_token', 'stripe_webhook_secret', 'pixel_id', 'graph_version', 'graph_base', 'test_event_code', 'site_url'] as $k) $cfg[$k] = trim((string)$cfg[$k]);
  $cfg['allowed_hosts'] = array_map('strtolower', array_map('strval', (array)$cfg['allowed_hosts']));
  return $cfg;
}

function capi_dir($sub = '') {
  $d = capi_home() . '/.ambar_capi' . ($sub ? '/' . $sub : '');
  if (!is_dir($d)) @mkdir($d, 0700, true);
  return $d;
}

/* log sem dados pessoais */
function capi_log($msg) {
  @file_put_contents(capi_dir() . '/log.txt', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

function capi_valid_ref($r) { return is_string($r) && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $r); }

/* ---------- normalização + hash SHA-256 (padrão da Meta) ---------- */
function capi_h($v) { return ($v === null || $v === '') ? null : hash('sha256', $v); }
function capi_norm_email($e) {
  $e = strtolower(trim((string)$e));
  return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}
function capi_norm_phone($p) {
  $d = preg_replace('/\D+/', '', (string)$p);
  $d = ltrim($d, '0');
  if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;  /* número brasileiro sem DDI */
  return strlen($d) >= 12 ? $d : '';
}
function capi_norm_name($n) {
  $n = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$n), 'UTF-8') : strtolower(trim((string)$n));
  return trim((string)preg_replace('/[^\p{L}\s-]/u', '', $n));   /* UTF-8 inválido vira '' */
}
function capi_split_name($full) {
  $parts = preg_split('/\s+/', trim((string)$full), -1, PREG_SPLIT_NO_EMPTY);
  if (!$parts) return ['', ''];
  $first = array_shift($parts);
  return [$first, $parts ? end($parts) : ''];
}

/* monta user_data a partir de dados crus (e-mail, telefone, nome) + contexto do navegador */
function capi_user_data($pii, $ctx) {
  $em = capi_norm_email($pii['email'] ?? '');
  $ph = capi_norm_phone($pii['tel'] ?? '');
  $fn = capi_norm_name($pii['nome'] ?? '');
  $ln = capi_norm_name($pii['sobrenome'] ?? '');
  $u = array_filter([
    'em' => $em ? [capi_h($em)] : null,
    'ph' => $ph ? [capi_h($ph)] : null,
    'fn' => $fn ? [capi_h($fn)] : null,
    'ln' => $ln ? [capi_h($ln)] : null,
    'country' => [capi_h('br')],
    'external_id' => $em ? [capi_h($em)] : null,
    'client_ip_address' => $ctx['ip'] ?? null,
    'client_user_agent' => $ctx['ua'] ?? null,
    'fbp' => $ctx['fbp'] ?? null,
    'fbc' => $ctx['fbc'] ?? null,
  ]);
  return $u;
}

/* contexto do visitante (IP, navegador, cookies do Pixel), lido da própria requisição */
function capi_request_context() {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $f = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (filter_var($f, FILTER_VALIDATE_IP)) $ip = $f;
  }
  $fbp = $_COOKIE['_fbp'] ?? '';
  $fbc = $_COOKIE['_fbc'] ?? '';
  return array_filter([
    'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
    'ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400) ?: null,
    'fbp' => preg_match('/^fb\.\d\.\d+\.\d+$/', $fbp) ? $fbp : null,
    'fbc' => preg_match('/^fb\.\d\.\d+\.[A-Za-z0-9_-]+$/', $fbc) ? $fbc : null,
  ]);
}

function capi_ctx_save($ref, $data) {
  @file_put_contents(capi_dir('ctx') . '/' . $ref . '.json', json_encode($data, CAPI_JSON), LOCK_EX);
  capi_cleanup();
}
function capi_ctx_load($ref) {
  if (!capi_valid_ref($ref)) return [];
  $d = json_decode((string)@file_get_contents(capi_dir('ctx') . '/' . $ref . '.json'), true);
  return is_array($d) ? $d : [];
}
/* apaga contextos e marcas de envio com mais de 30 dias (no máximo uma varredura por dia) */
function capi_cleanup() {
  $marca = capi_dir() . '/ultima_limpeza';
  if (is_file($marca) && filemtime($marca) > time() - 86400) return;
  @touch($marca);
  foreach (['ctx', 'enviados', 'rl'] as $sub) {
    foreach (glob(capi_dir($sub) . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 30 * 86400) @unlink($f);
  }
}

/* envia um evento para a API de Conversões. Retorna true se a Meta aceitou. */
function capi_send($event, $label) {
  $c = capi_config();
  $payload = ['data' => json_encode([$event], CAPI_JSON)];
  if ($c['test_event_code'] !== '') $payload['test_event_code'] = $c['test_event_code'];

  if (!empty($c['dry_run'])) {
    @file_put_contents(capi_dir() . '/dryrun.jsonl', json_encode($event, CAPI_JSON) . "\n", FILE_APPEND | LOCK_EX);
    capi_log("DRY-RUN $label {$event['event_name']} id={$event['event_id']}");
    return true;
  }
  if ($c['meta_token'] === '') { capi_log("ERRO $label: sem meta_token na configuracao"); return false; }

  $payload['access_token'] = $c['meta_token'];
  $url = rtrim($c['graph_base'], '/') . '/' . $c['graph_version'] . '/' . rawurlencode($c['pixel_id']) . '/events';
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
  ]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
  curl_close($ch);
  $d = json_decode((string)$res, true);
  if ($code === 200 && !empty($d['events_received'])) {
    capi_log("OK $label {$event['event_name']} id={$event['event_id']}" . ($c['test_event_code'] !== '' ? ' (teste)' : ''));
    return true;
  }
  $msg = $d['error']['message'] ?? ($err ?: 'resposta inesperada');
  capi_log("ERRO $label {$event['event_name']} id={$event['event_id']} http=$code: " . substr($msg, 0, 200));
  return false;
}

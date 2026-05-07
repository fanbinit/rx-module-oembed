<?php

namespace Rhymix\Modules\Oembed\Models;

use Rhymix\Framework\HTTP;

/**
 * 외부 URL 에서 HTML/이미지를 가져오는 래퍼.
 *
 * - Rhymix\Framework\HTTP 사용 (Guzzle 기반)
 * - SSRF 가드: 입력 URL 과 redirect 대상 모두 사설/예약 IP 차단,
 *   localhost/메타데이터 엔드포인트(169.254.169.254) 차단
 * - 리다이렉트 5회 제한, body 사이즈 상한, 타임아웃 3초
 *
 * 실패 시 모두 null 을 돌려준다 (호출자는 graceful 폴백).
 */
class RemoteFetcher
{
  public const TIMEOUT_SECONDS = 3;
  public const MAX_REDIRECTS = 5;
  public const MAX_HTML_BYTES = 2 * 1024 * 1024; // 2MB
  public const MAX_IMAGE_BYTES = 5 * 1024 * 1024; // 5MB
  public const URL_MAX_LENGTH = 2048;
  public const USER_AGENT = 'Mozilla/5.0 (compatible; oEmbedBot/0.1; +https://github.com/zodkr/rx-module-oembed)';

  /**
   * Normalize a user-supplied URL.
   *
   *  - 빈/너무 긴 값 거부
   *  - 프로토콜 없으면 https:// 부여
   *  - host 가 비었거나 형식이 부적합하면 거부
   *
   * Returns the normalized URL or null when the input is unusable.
   * SSRF 가드(사설 IP 차단 등)는 별도로 isUrlSafe() 가 처리한다.
   */
  public static function normalizeUrl(string $raw): ?string
  {
    $url = trim($raw);
    if ($url === '' || strlen($url) > self::URL_MAX_LENGTH) {
      return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
      $url = 'https://' . ltrim($url, '/');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
      return null;
    }
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $parts['host'])) {
      return null;
    }
    return $url;
  }

  /**
   * Fetch HTML at $url.
   *
   * @return array{status:int, body:string, final_url:string, content_type:string}|null
   */
  public static function fetchHtml(string $url): ?array
  {
    $info = self::inspectUrl($url);
    if ($info === null) {
      return null;
    }
    $response = HTTP::get($url, null, [
      'User-Agent' => self::USER_AGENT,
      'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.7',
      'Accept-Language' => 'ko,en;q=0.7',
    ], [], [
      'timeout' => self::TIMEOUT_SECONDS,
      'connect_timeout' => self::TIMEOUT_SECONDS,
      'allow_redirects' => self::redirectOptions($info['host'], $info['port']),
      'curl' => [CURLOPT_RESOLVE => self::buildResolveEntries($info['host'], $info['port'], $info['ips'])],
    ]);

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      return null;
    }
    $contentType = $response->getHeaderLine('Content-Type');
    if (!preg_match('#(?:text/html|application/xhtml)#i', $contentType)) {
      return null;
    }

    $body = self::readLimitedBody($response, self::MAX_HTML_BYTES);
    $redirectHistory = $response->getHeader('X-Guzzle-Redirect-History');
    $finalUrl = end($redirectHistory) ?: $url;

    return [
      'status' => $status,
      'body' => $body,
      'final_url' => is_string($finalUrl) ? $finalUrl : $url,
      'content_type' => $contentType,
    ];
  }

  /**
   * Fetch JSON at $url and decode to associative array.
   *
   * fetchHtml 과 동일한 SSRF / timeout / redirect 가드를 통과하지만 Accept /
   * content-type 가드를 application/json 으로 좁힌다. body cap 은 fetchHtml
   * 과 같은 MAX_HTML_BYTES (2MB) 를 재사용 — oEmbed 응답은 보통 1KB 안팎이라
   * 별도 cap 을 두지 않아도 충분하다.
   *
   * @return array<string,mixed>|null
   */
  public static function fetchJson(string $url): ?array
  {
    $info = self::inspectUrl($url);
    if ($info === null) {
      return null;
    }
    $response = HTTP::get($url, null, [
      'User-Agent' => self::USER_AGENT,
      'Accept' => 'application/json',
    ], [], [
      'timeout' => self::TIMEOUT_SECONDS,
      'connect_timeout' => self::TIMEOUT_SECONDS,
      'allow_redirects' => self::redirectOptions($info['host'], $info['port']),
      'curl' => [CURLOPT_RESOLVE => self::buildResolveEntries($info['host'], $info['port'], $info['ips'])],
    ]);

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      return null;
    }
    $contentType = $response->getHeaderLine('Content-Type');
    if (!preg_match('#application/json#i', $contentType)) {
      return null;
    }
    $body = self::readLimitedBody($response, self::MAX_HTML_BYTES);
    $payload = json_decode($body, true);
    return is_array($payload) ? $payload : null;
  }

  /**
   * Fetch image bytes at $url. Returns null on any failure or if the response
   * exceeds MAX_IMAGE_BYTES / is not an image MIME.
   *
   * @return array{body:string, content_type:string}|null
   */
  public static function fetchImage(string $url): ?array
  {
    $info = self::inspectUrl($url);
    if ($info === null) {
      return null;
    }
    $response = HTTP::get($url, null, [
      'User-Agent' => self::USER_AGENT,
      'Accept' => 'image/*',
    ], [], [
      'timeout' => self::TIMEOUT_SECONDS,
      'connect_timeout' => self::TIMEOUT_SECONDS,
      'allow_redirects' => self::redirectOptions($info['host'], $info['port']),
      'curl' => [CURLOPT_RESOLVE => self::buildResolveEntries($info['host'], $info['port'], $info['ips'])],
    ]);

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      return null;
    }
    $contentType = $response->getHeaderLine('Content-Type');
    if (!preg_match('#^image/#i', $contentType)) {
      return null;
    }
    $body = self::readLimitedBody($response, self::MAX_IMAGE_BYTES, true);
    if ($body === null) {
      return null;
    }
    return ['body' => $body, 'content_type' => $contentType];
  }

  /**
   * URL 의 SSRF 안전성을 boolean 으로 알려주는 thin wrapper. 실제 검증
   * 로직은 inspectUrl() 가 들고 있고 여기서는 결과의 boolean 만 노출한다.
   *  - scheme http/https only
   *  - host present, non-empty
   *  - DNS resolves to public IP only, or host is itself a public IP literal
   */
  public static function isUrlSafe(string $url): bool
  {
    return self::inspectUrl($url) !== null;
  }

  /**
   * URL 파싱 + SSRF 검증 + IP-pin 용 정보 수집을 한 번에 수행한다.
   *
   * 검증 통과 시 호스트의 정규화된 이름, 명시 또는 scheme 기본값으로
   * 결정된 port, 그리고 dns_get_record 의 검증 통과 IP 목록을 함께
   * 반환한다. 이 IP 목록은 곧이어 fetchHtml / fetchJson / fetchImage
   * 가 cURL 의 CURLOPT_RESOLVE 에 박아서, connect 시점에 DNS 가 다시
   * 조회되지 않도록 강제하는 데 쓴다 — DNS rebinding TOCTOU 차단의
   * 핵심.
   *
   * @return array{host:string, port:int, ips:array<int,string>}|null
   */
  private static function inspectUrl(string $url): ?array
  {
    $parts = parse_url($url);
    if (!is_array($parts)) {
      return null;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
      return null;
    }
    $host = $parts['host'] ?? '';
    $ips = self::resolveHostSafely($host);
    if ($ips === null) {
      return null;
    }
    return [
      'host' => strtolower(trim($host, '[]')),
      'port' => isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80),
      'ips' => $ips,
    ];
  }

  /**
   * 호스트가 SSRF 안전한지 boolean 으로 알려주는 thin wrapper. 외부 호환용.
   * 실제 검증과 IP 추출은 resolveHostSafely() 가 들고 있다.
   */
  public static function isHostSafe(string $host): bool
  {
    return self::resolveHostSafely($host) !== null;
  }

  /**
   * 호스트의 SSRF 안전성을 검증하면서 검증 통과한 IP 목록도 함께 돌려준다.
   * Loopback, link-local, RFC1918, cloud metadata IP 는 차단된다.
   * dns_get_record 가 돌려준 모든 A/AAAA 레코드의 IP 가 public 이어야만
   * 통과한다 — 한 개라도 사설/예약 IP 면 즉시 null 반환.
   *
   * 통과 시 그 IP 목록을 그대로 반환하므로, 호출자(inspectUrl) 가 cURL
   * CURLOPT_RESOLVE 에 박아 실제 connect 단계에서 DNS 가 다시 조회되지
   * 않게 강제할 수 있다 — 이것이 DNS rebinding 으로 검증 후 IP 가 바꿔
   * 치기 되는 TOCTOU 우회를 막는 핵심이다.
   *
   * IP 리터럴 입력은 그 IP 한 개만 담긴 1-원소 배열을 돌려준다.
   *
   * @return array<int,string>|null  안전하면 IPv4/IPv6 문자열 목록(비어있지 않음), 아니면 null
   */
  public static function resolveHostSafely(string $host): ?array
  {
    $host = strtolower(trim($host, '[]'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
      return null;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      return self::isPublicIp($host) ? [$host] : null;
    }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
    if (!$records) {
      return null;
    }
    $ips = [];
    foreach ($records as $record) {
      $ip = $record['ip'] ?? ($record['ipv6'] ?? '');
      if ($ip === '' || !self::isPublicIp($ip)) {
        return null;
      }
      $ips[] = $ip;
    }
    return $ips !== [] ? $ips : null;
  }

  private static function isPublicIp(string $ip): bool
  {
    if ($ip === '169.254.169.254' || $ip === 'fd00:ec2::254') {
      return false;
    }
    return (bool) filter_var(
      $ip,
      FILTER_VALIDATE_IP,
      FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
  }

  /**
   * Guzzle 의 allow_redirects payload 를 만든다.
   *
   * `$expectedHost` / `$expectedPort` 는 첫 hop 의 origin 으로, on_redirect
   * 콜백이 cross-origin redirect 를 차단하는 데 쓴다. 첫 hop 에서 박은
   * CURLOPT_RESOLVE 매핑은 정확히 `(host, port)` 한 쌍에만 적용된다 —
   * 호스트가 같아도 port 가 다르면(예: 80→8080, http→https 업그레이드로
   * 인한 80↔443) 그 새 origin 은 IP-pin 없이 connect-time 에 새 DNS
   * resolve 가 일어나 동일한 TOCTOU 가 다시 노출된다. 따라서 host 와 port
   * 둘 다 첫 hop 과 동일해야만 redirect 를 허용한다.
   *
   * 같은 origin 안의 redirect 는 cURL 이 같은 핸들의 resolve 캐시를
   * 재사용하므로 IP-pin 이 그대로 유효.
   *
   * @return array<string,mixed>
   */
  private static function redirectOptions(string $expectedHost, int $expectedPort): array
  {
    return [
      'max' => self::MAX_REDIRECTS,
      'strict' => true,
      'protocols' => ['http', 'https'],
      'track_redirects' => true,
      'on_redirect' => static function ($request, $response, $uri) use ($expectedHost, $expectedPort) {
        $host = strtolower($uri->getHost());
        // PSR UriInterface::getPort() 는 명시 안 됐을 때 null 반환 → scheme
        // 기본값으로 폴백한다.
        $port = $uri->getPort();
        if ($port === null) {
          $port = strtolower($uri->getScheme()) === 'https' ? 443 : 80;
        }
        if ($host !== $expectedHost || (int) $port !== $expectedPort) {
          throw new \RuntimeException('oembed: cross-origin redirect blocked');
        }
        if (self::resolveHostSafely($host) === null) {
          throw new \RuntimeException('oembed: redirect blocked (unsafe host)');
        }
      },
    ];
  }

  /**
   * cURL CURLOPT_RESOLVE entry 형식 (`host:port:ip`) 으로 변환한다. IPv6 는
   * entry 형식 규약상 IP 부분을 대괄호로 감싼다 (`host:port:[::1]`).
   *
   * @param array<int,string> $ips
   * @return array<int,string>
   */
  private static function buildResolveEntries(string $host, int $port, array $ips): array
  {
    $entries = [];
    foreach ($ips as $ip) {
      $entries[] = sprintf('%s:%d:%s', $host, $port, str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }
    return $entries;
  }

  /**
   * Read the response body in chunks up to $maxBytes. If $rejectOversize is true
   * and the source exceeds $maxBytes, return null instead of truncating.
   */
  private static function readLimitedBody($response, int $maxBytes, bool $rejectOversize = false): ?string
  {
    $stream = $response->getBody();
    $body = '';
    while (!$stream->eof()) {
      $chunk = $stream->read(8192);
      if ($chunk === '' || $chunk === false) {
        break;
      }
      $body .= $chunk;
      if (strlen($body) > $maxBytes) {
        if ($rejectOversize) {
          return null;
        }
        return substr($body, 0, $maxBytes);
      }
    }
    return $body;
  }
}

<div class="x_page-header">
  <h1>{{ $lang->cmd_oembed }}</h1>
</div>

<ul class="x_nav x_nav-tabs">
  <li @class(['x_active' => $act === 'dispOembedAdminConfig'])>
    <a href="{{ getUrl('', 'module', 'admin', 'act', 'dispOembedAdminConfig') }}">{{ $lang->cmd_oembed_general_config }}</a>
  </li>
  <li @class(['x_active' => $act === 'dispOembedAdminProviders'])>
    <a href="{{ getUrl('', 'module', 'admin', 'act', 'dispOembedAdminProviders') }}">{{ $lang->cmd_oembed_providers }}</a>
  </li>
</ul>

@if (!empty($oembed_preview_active) || !empty($oembed_preview_conflict))
  <div class="x_alert x_alert-warning oembed-alert">
    {!! $lang->oembed_preview_active_warning !!}
  </div>
@endif

@php
  $missingHosts = $oembed_missing_hosts ?? [];
  $enabledProviders = $oembed_config->enabled_providers ?? [];
  $hostStatus = $oembed_host_whitelist ?? [];
@endphp

@if (count($missingHosts) > 0)
  <section class="section">
    <h2>{{ $lang->oembed_missing_hosts_title }}</h2>
  </section>
  <div class="x_page-content oembed-missing-panel">
    <p class="oembed-missing-intro">{!! $lang->oembed_missing_hosts_intro !!}</p>
    <textarea readonly rows="{{ min(count($missingHosts) + 1, 12) }}"
              class="oembed-missing-textarea" onclick="this.select();">{{ implode("\n", $missingHosts) }}</textarea>
    <div class="btnArea x_clearfix">
      <a class="x_btn x_btn-primary" href="{{ getUrl('', 'module', 'admin', 'act', 'dispAdminConfigSecurity') }}#mediafilter_whitelist" target="_blank" rel="noopener">
        {{ $lang->oembed_open_security_config }} →
      </a>
    </div>
  </div>
@endif

<section class="section">
  <h2>{{ $lang->cmd_oembed_providers }}</h2>
  <form action="{{ getUrl() }}" method="post" class="oembed-refresh-form">
    <input type="hidden" name="module" value="admin" />
    <input type="hidden" name="act" value="procOembedAdminRefreshProviders" />
    <input type="hidden" name="success_return_url" value="{{ getCurrentPageUrl() }}" />
    <button type="submit" class="x_btn x_btn-default">{{ $lang->oembed_refresh_providers }}</button>
    <small class="oembed-refresh-desc">{{ $lang->oembed_refresh_providers_desc }}</small>
  </form>
</section>

<form action="{{ getUrl() }}" method="post" id="oembedProvidersForm">
  <input type="hidden" name="module" value="admin" />
  <input type="hidden" name="act" value="procOembedAdminInsertConfig" />
  <input type="hidden" name="screen" value="providers" />
  <input type="hidden" name="success_return_url" value="{{ getCurrentPageUrl() }}" />

  <div class="x_page-content">
    <table class="x_table oembed-providers-table">
      <colgroup>
        <col style="width: 48px;" />
        <col style="width: 140px;" />
        <col style="width: 110px;" />
        <col style="width: 80px;" />
        <col />
      </colgroup>
      <thead>
        <tr>
          <th class="x_text-center">{{ $lang->oembed_provider_enabled }}</th>
          <th>{{ $lang->oembed_provider_name }}</th>
          <th>{{ $lang->oembed_provider_type }}</th>
          <th>{{ $lang->oembed_provider_oembed }}</th>
          <th>{{ $lang->oembed_provider_hosts }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($oembed_providers as $key => $provider)
          @php
            $isEnabled = in_array($key, $enabledProviders, true);
            $typeLabel = $provider->type === 'multimedia'
              ? $lang->oembed_provider_type_multimedia
              : $lang->oembed_provider_type_social;
            $providerHosts = $hostStatus[$key] ?? [];
            $checkboxId = 'oembed-provider-' . $key;
          @endphp
          <tr class="{{ $isEnabled ? '' : 'oembed-row-disabled' }}">
            <td class="x_text-center">
              <label class="oembed-toggle">
                <input type="checkbox" id="{{ $checkboxId }}" name="enabled_providers[]" value="{{ $key }}" {{ $isEnabled ? 'checked' : '' }} />
              </label>
            </td>
            <td>
              <label for="{{ $checkboxId }}" class="oembed-provider-label">
                <strong>{{ $provider->name }}</strong>
                <small class="oembed-provider-key">{{ $key }}</small>
              </label>
            </td>
            <td>{{ $typeLabel }}</td>
            <td>{{ $provider->oembed ? 'O' : '–' }}</td>
            <td>
              @foreach ($provider->getEmbedHosts() as $host)
                @php $isWhitelisted = $providerHosts[$host] ?? false; @endphp
                <span class="oembed-host-badge {{ $isWhitelisted ? 'is-allowed' : 'is-pending' }}"
                      title="{{ $isWhitelisted ? $lang->oembed_host_whitelisted : $lang->oembed_host_not_whitelisted }}">
                  {{ $isWhitelisted ? '✓' : '!' }} {{ $host }}
                </span>
              @endforeach
            </td>
          </tr>
        @endforeach
        @if (count($oembed_providers) === 0)
          <tr><td colspan="5" class="x_text-center oembed-empty">no providers found</td></tr>
        @endif
      </tbody>
    </table>

    <p class="oembed-help">{{ $lang->oembed_providers_help }}</p>
  </div>

  <div class="btnArea x_clearfix">
    <button type="submit" class="x_btn x_btn-primary x_pull-right">{{ $lang->cmd_registration }}</button>
  </div>
</form>

<style>
  .oembed-alert { margin-bottom: 12px; }

  .oembed-missing-panel {
    border: 1px solid #f5c6cb; border-radius: 6px;
    padding: 16px 20px; background: #fff8f8;
  }
  .oembed-missing-intro { margin: 0 0 12px; }
  .oembed-missing-textarea {
    width: 100%; padding: 8px; font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
    font-size: 0.92em; border: 1px solid #ddd; border-radius: 4px; background: #fff;
    margin-bottom: 8px;
  }

  .oembed-refresh-form { display: inline-flex; align-items: center; gap: 10px; margin-top: 6px; }
  .oembed-refresh-desc { color: #888; font-size: 0.88em; }

  .oembed-providers-table { width: 100%; }
  .oembed-providers-table th, .oembed-providers-table td {
    padding: 8px 10px; vertical-align: middle;
  }
  .oembed-row-disabled { background: #fafafa; }
  .oembed-row-disabled td:not(:first-child) { opacity: 0.55; }
  .oembed-toggle { display: inline-flex; cursor: pointer; padding: 4px; }
  .oembed-toggle input { width: 18px; height: 18px; cursor: pointer; }
  .oembed-provider-label { cursor: pointer; display: inline-block; }
  .oembed-provider-label:hover strong { text-decoration: underline; }
  .oembed-provider-key { color: #888; margin-left: 6px; font-weight: normal; }
  .oembed-empty { color: #999; padding: 20px 0 !important; }

  .oembed-host-badge {
    display: inline-block; padding: 2px 8px; margin: 2px 4px 2px 0;
    border-radius: 3px; font-size: 0.85em; line-height: 1.4;
    border: 1px solid transparent;
  }
  /* 등록된 호스트는 한 줄에 여러 개가 늘어서면 배경 뱃지가 시각적 노이즈로 작용한다.
     이쪽은 ✓ 글자색만 남기고, 미등록(주의가 필요한) 호스트만 배경으로 강조한다. */
  .oembed-host-badge.is-allowed { color: #1e8e3e; padding-left: 0; padding-right: 0; }
  .oembed-host-badge.is-pending { background: #fce8e6; color: #c5221f; border-color: #f5c6cb; }

  .oembed-help { margin: 12px 0 0; color: #666; font-size: 0.92em; }
</style>

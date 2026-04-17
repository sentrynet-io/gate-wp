<?php

declare(strict_types=1);

namespace SentryNet;

class Settings
{
    private const OPTION_KEY = 'sentrynet_gate';

    public function register_menu(): void
    {
        add_menu_page(
            'SentryNet',
            'SentryNet',
            'manage_options',
            'sentrynet-gate',
            [$this, 'render_page'],
            'dashicons-shield',
            40
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [$this, 'sanitize'],
        ]);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        return array_merge([
            'enabled'     => 0,
            'cookie_name' => 'sentrynet_ftoken',
            'app_id'      => '',
            'api_key'     => '',
        ], (array) get_option(self::OPTION_KEY, []));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(array $input): array
    {
        return [
            'enabled'     => empty($input['enabled']) ? 0 : 1,
            'cookie_name' => sanitize_text_field($input['cookie_name'] ?? 'sentrynet_ftoken'),
            'app_id'      => sanitize_text_field($input['app_id'] ?? ''),
            'api_key'     => sanitize_text_field($input['api_key'] ?? ''),
        ];
    }

    public function render_page(): void
    {
        $config = $this->get();
        ?>
        <div class="wrap">
            <h1>SentryNet Protection</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="sn_enabled">Enabled?</label>
                        </th>
                        <td>
                            <input
                                type="checkbox"
                                id="sn_enabled"
                                name="sentrynet_gate[enabled]"
                                value="1"
                                <?php checked(1, $config['enabled']) ?>
                            />
                            <p class="description">WARNING: Disabling this will leave your site unprotected!</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sn_cookie_name">Cookie Name</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="sn_cookie_name"
                                name="sentrynet_gate[cookie_name]"
                                value="<?php echo esc_attr($config['cookie_name']); ?>"
                                class="regular-text"
                            />
                            <p class="description">The cookie used to store the visitor's JWT token.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sn_app_id">X-App-Id</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="sn_app_id"
                                name="sentrynet_gate[app_id]"
                                value="<?php echo esc_attr($config['app_id']); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sn_api_key">X-Api-Key</label>
                        </th>
                        <td>
                            <input
                                type="password"
                                id="sn_api_key"
                                name="sentrynet_gate[api_key]"
                                value="<?php echo esc_attr($config['api_key']); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

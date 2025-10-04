<?php

namespace classes\Ui;

class ServerSelect
{
    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderServerSelect'], 20);
    }

    /**
     * Check if product requires server select
     */
    private function needsServerSelect(int $productId): bool
    {
        $type = get_post_meta($productId, '_xshop_type', true);
        $subtype = get_post_meta($productId, '_xshop_subtype', true);

        return ($type === 'topup' && (string)$subtype === '2');
    }

    /**
     * Render custom select on products with servers
     */
    public function renderServerSelect(): void
    {
        global $post;
        if (!$post || empty($post->ID)) return;

        if (!$this->needsServerSelect($post->ID)) return;

        $meta = json_decode(get_post_meta($post->ID, 'xshop_json', true));
        if (empty($meta->servers) || !is_array($meta->servers)) return;

        $servers = [];
        foreach ($meta->servers as $s) {
            if (!empty($s->id) && !empty($s->name)) {
                $servers[$s->id] = $s->name;
            }
        }
        asort($servers, SORT_STRING);

        echo '<div class="cubixsol-server-select-wrapper" style="margin:15px 0;text-align:center;">';
        echo '<label for="server" class="cubixsol-server-select-label" style="display:block;margin-bottom:8px;">Server *</label>';

        // main select
        echo '<select name="server" id="server" class="cubixsol-server-select" required>';
        echo '<option value="">-الرجاء الاختيار-</option>';

        foreach ($servers as $id => $srv) {
            echo '<option value="' . esc_attr(strtolower(str_replace(' ', '-', $srv))) . '" data-id="' . esc_attr($id) . '">'
                . esc_html($srv)
                . '</option>';
        }

        echo '</select>';
        echo '<input type="hidden" name="server_id" id="server_id" value="">';
        echo '</div>';

        // inline JS
        echo '<script>
        document.addEventListener("DOMContentLoaded", function () {
            const select = document.getElementById("server");
            const hidden = document.getElementById("server_id");
            if (select && hidden) {
                select.addEventListener("change", function () {
                    const option = select.options[select.selectedIndex];
                    hidden.value = option.getAttribute("data-id") || "";
                });
            }
        });
        </script>';

        // styles
        echo '<style>
            .cubixsol-server-select {
                background: black;
                color: white;
                text-align: center;
                border: 1px solid #444;
                padding: 8px 12px;
                border-radius: 4px;
            }
            .cubixsol-server-select option {
                background: black;
                color: white;
                text-align: center;
            }
            .cubixsol-server-select option:checked {
                background: yellow;
                color: black;
            }
            .cubixsol-server-select option:hover {
                background: powderblue;
                color: black;
            }
        </style>';
    }
}

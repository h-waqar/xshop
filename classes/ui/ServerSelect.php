<?php /** @noinspection SpellCheckingInspection */

namespace classes\ui;

//classes/ui/ServerSelect.php:5

class ServerSelect
{
    // Define the products where select should appear
    private array $targetSlugs = ['legacy-fate-sacred-and-fearless-crossborder','mobile-legends-codashop', 'mobile-legends-cross-border'];

    public function __construct()
    {
        add_action('woocommerce_before_add_to_cart_button', [$this, 'renderServerSelect'], 20);
    }

    /**
     * Render custom select on specific products
     */
    public function old_renderServerSelect()
    {
        global $post;

        if (!$post || empty($post->post_name)) return;

        // Only for products in our target array
        if (!in_array($post->post_name, $this->targetSlugs, true)) return;

        $meta = json_decode(get_post_meta($post->ID, 'xshop_json', true));

        $servers = [];
        foreach ($meta->servers as $s) {
            $servers[$s->id] = $s->name;
        }

        asort($servers, SORT_STRING);

        echo '<div class="cubixsol-server-select-wrapper" style="margin:15px 0;text-align: center;">';
        echo '<label for="server" class="cubixsol-server-select-label" style="display:block;margin-bottom:8px;">Server *</label>';
        echo '<select name="server" id="server" class="cubixsol-server-select" required>';
        echo '<option value="">-الرجاء الاختيار-</option>';

        foreach ($servers as $id => $srv) {
            echo '<option value="' . esc_attr(strtolower(str_replace(' ', '-', $srv))) . '" data-id="' . esc_attr($id) . '">'
                . esc_html($srv)
                . '</option>';
        }

        echo '</select>';
        echo '</div>';

// Add custom styles
        echo '<style>
            .cubixsol-server-select-wrapper {
                text-align: center;
            }
            
            .cubixsol-server-select-label {
                text-align: center;
            }
            
            .cubixsol-server-select {
                background: black;
                color: white;
                text-align: center;
                border: 1px solid #444;
                padding: 8px 12px;
                border-radius: 4px;
            }
            
            /* Default option style */
            .cubixsol-server-select option {
                background: black;
                color: white;
                text-align: center;
            }
            
            /* Selected option */
            .cubixsol-server-select option:checked {
                background: yellow;
                color: black;
            }
            
            /* Hover (works in Chrome/Edge/Firefox, not Safari/iOS) */
            .cubixsol-server-select option:hover {
                background: powderblue;
                color: black;
            }
        </style>';

    }


    public function renderServerSelect()
    {
        global $post;
        if (!$post || empty($post->post_name)) return;

        if (!in_array($post->post_name, $this->targetSlugs, true)) return;

        $meta = json_decode(get_post_meta($post->ID, 'xshop_json', true));
        if (empty($meta->servers)) return;

        $servers = [];
        foreach ($meta->servers as $s) {
            $servers[$s->id] = $s->name;
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

        // hidden input for server_id
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
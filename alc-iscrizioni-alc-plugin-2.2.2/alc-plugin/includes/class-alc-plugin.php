<?php
if (!defined('ABSPATH')) { exit; }

class ALC_Plugin {
    const CPT = 'alc_iscrizione';
    const OPT = 'alc_settings';

    public static function activate(){
        self::register_cpt();
        flush_rewrite_rules();
        if (!get_option(self::OPT)){
            add_option(self::OPT, [
                'currency' => 'EUR',
                'fees' => [
                    'romanzo_inedito' => 20.00,
                    'romanzo_edito' => 20.00,
                    'racconto_edito' => 10.00,
                    'poesia_inedita' => 8.00,
                ],
                'drive_enabled' => 0,
                'drive_client_id' => '',
                'drive_client_secret' => '',
                'drive_refresh_token' => '',
                'drive_folder_id' => '',
            ]);
        }
    }
    public static function deactivate(){ flush_rewrite_rules(); }

    public function __construct(){
        add_action('init', [__CLASS__,'register_cpt']);
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'register_settings']);
        add_shortcode('alc_submission_form', [$this,'render_form']);
        add_action('wp_enqueue_scripts', [$this,'assets']);
        add_action('wp_ajax_alc_submit', [$this,'handle_submit']);
        add_action('wp_ajax_nopriv_alc_submit', [$this,'handle_submit']);
        add_action('wp_ajax_alc_verify', [$this,'handle_verify']);
        add_action('wp_ajax_nopriv_alc_verify', [$this,'handle_verify']);
        add_action('add_meta_boxes', [$this,'add_metabox']);
    }

    public static function register_cpt(){
        register_post_type(self::CPT, [
            'label' => 'Iscrizioni ALC',
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-welcome-write-blog',
            'supports' => ['title','editor'],
        ]);
    }

    public function admin_menu(){
        add_menu_page('ALC Iscrizioni','ALC Iscrizioni','manage_options','alc-iscrizioni',[$this,'settings_page'],'dashicons-book',56);
    }
    public function register_settings(){
        register_setting(self::OPT, self::OPT);
        add_settings_section('alc_main','Impostazioni principali',function(){
            echo '<p>Configura quote, valuta e (opzionale) Google Drive.</p>';
        }, self::OPT);
        add_settings_field('currency','Valuta',function(){
            $opt = get_option(self::OPT);
            printf('<input name="%s[currency]" value="%s" class="regular-text" />', self::OPT, esc_attr($opt['currency'] ?? 'EUR'));
        }, self::OPT,'alc_main');
        add_settings_field('fees','Quote per categoria',function(){
            $opt = get_option(self::OPT);
            $fees = $opt['fees'] ?? [];
            $fields = [
                'romanzo_inedito' => 'Romanzo inedito',
                'romanzo_edito' => 'Romanzo edito',
                'racconto_edito' => 'Racconto edito',
                'poesia_inedita' => 'Componimento poetico inedito',
            ];
            echo '<table class="form-table"><tbody>';
            foreach($fields as $k=>$label){
                $val = isset($fees[$k]) ? floatval($fees[$k]) : 0;
                printf('<tr><th>%s</th><td><input type="number" step="0.01" name="%s[fees][%s]" value="%s"/></td></tr>', esc_html($label), self::OPT, esc_attr($k), esc_attr($val));
            }
            echo '</tbody></table>';
        }, self::OPT,'alc_main');

        add_settings_section('alc_drive','Google Drive (opzionale)',function(){
            echo '<p>Abilita caricamento su cartella condivisa Google Drive. Serve Client ID, Client Secret, Refresh Token e Folder ID con permessi di scrittura.</p>';
        }, self::OPT);
        add_settings_field('drive_enabled','Abilita Drive',function(){
            $opt = get_option(self::OPT); $checked = !empty($opt['drive_enabled']) ? 'checked' : '';
            printf('<label><input type="checkbox" name="%s[drive_enabled]" value="1" %s/> Usa Google Drive</label>', self::OPT, $checked);
        }, self::OPT,'alc_drive');
        foreach(['drive_client_id'=>'Client ID', 'drive_client_secret'=>'Client Secret', 'drive_refresh_token'=>'Refresh Token', 'drive_folder_id'=>'Folder ID'] as $key=>$label){
            add_settings_field($key,$label,function() use ($key){
                $opt = get_option(self::OPT);
                printf('<input name="%s[%s]" value="%s" class="regular-text" />', self::OPT, esc_attr($key), esc_attr($opt[$key] ?? ''));
            }, self::OPT,'alc_drive');
        }
    }
    public function settings_page(){ ?>
        <div class="wrap">
            <h1>Agitazioni Letterarie Castelluccesi — Iscrizioni</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); do_settings_sections(self::OPT); submit_button(); ?>
            </form>
            <h2>Pagamento</h2>
            <p><strong>IBAN:</strong> IT90R0760104200001071784589<br/>
            Intestato a: <em>Ass. culturale Completa.Mente</em><br/>
            Causale: “Iscrizione al Concorso Letterario Agitazioni Letterarie Castelluccesi”.</p>
            <h2>Shortcode</h2>
            <p>Inserisci il form nella pagina dedicata con: <code>[alc_submission_form]</code></p>
        </div>
    <?php }

    public function assets(){
        wp_register_style('alc-style', ALC_PLUGIN_URL.'assets/css/style.css', [], ALC_VERSION);
        wp_register_script('alc-form', ALC_PLUGIN_URL.'assets/js/form.js', ['jquery'], ALC_VERSION, true);
        $opt = get_option(self::OPT);
        wp_localize_script('alc-form', 'ALC_VARS', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alc_nonce'),
            'fees' => $opt['fees'] ?? [],
            'currency' => $opt['currency'] ?? 'EUR',
            'max_file_mb' => 10
        ]);
    }

    public function render_form(){
        wp_enqueue_style('alc-style');
        wp_enqueue_script('alc-form');
        ob_start(); ?>
        <div class="alc-wrapper">
            <h2>Iscrizione al Concorso “Agitazioni Letterarie Castelluccesi”</h2>
            <p>Compila il modulo. Puoi iscriverti a più categorie (ogni categoria ha il suo titolo e il suo manoscritto).</p>

            <form id="alc-form" enctype="multipart/form-data">
                <fieldset>
                    <legend>1. Dati anagrafici</legend>
                    <div class="alc-grid">
                        <label>Nome *<input type="text" name="nome" required></label>
                        <label>Cognome *<input type="text" name="cognome" required></label>
                        <label>Email *<input type="email" name="email" required></label>
                        <label>Telefono <input type="text" name="telefono"></label>
                        <label>Data di nascita *<input type="date" name="nascita" required></label>
                        <label>Indirizzo <input type="text" name="indirizzo"></label>
                        <label>Città <input type="text" name="citta"></label>
                        <label>CAP <input type="text" name="cap" pattern="\d{4,10}"></label>
                        <label>Paese <input type="text" name="paese"></label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>2. Categoria e Opera</legend>
                    <div class="alc-cats">
                        <p>Spunta le categorie a cui vuoi iscriverti. Ogni categoria si espande per inserire il <strong>titolo</strong> e caricare il <strong>manoscritto</strong>.</p>
                        <label><input type="checkbox" name="categorie[]" value="romanzo_inedito"> Romanzo inedito (max 300000 caratteri, autori maggiorenni)</label>
                        <label><input type="checkbox" name="categorie[]" value="romanzo_edito"> Romanzo edito (max 300000 caratteri, autori maggiorenni; ammesso self publishing)</label>
                        <label><input type="checkbox" name="categorie[]" value="racconto_edito"> Racconto edito (max 35000 caratteri, autori maggiorenni)</label>
                        <label><input type="checkbox" name="categorie[]" value="poesia_inedita"> Componimento poetico inedito (max 36 versi, autori maggiorenni)</label>
                    </div>
                    <div class="alc-file">
                        <div id="alc-panels">
                            <!-- Pannelli espandibili per categoria -->
                        </div>
                    </div>
                </fieldset>

                <div class="alc-fee">
                    <strong>Quota totale: </strong>
                    <span id="alc-total">0</span> <span id="alc-currency"></span>
                </div>

                <div class="alc-consent">
                    <label><input type="checkbox" name="privacy" required> Dichiaro di aver letto e accettato l'informativa privacy.</label>
                </div>
                <div class="alc-consent">
                    <label><input type="checkbox" name="regolamento" required> Confermo di aver letto il regolamento del concorso.</label>
                </div>

                <div class="alc-actions">
                    <button type="submit" id="alc-submit" disabled>Invia candidatura</button>
                </div>

                <p class="alc-payment">
                    <strong>Pagamento:</strong> IBAN <code>IT90R0760104200001071784589</code>, intestato a <em>Ass. culturale Completa.Mente</em>, causale: “Iscrizione al Concorso Letterario Agitazioni Letterarie Castelluccesi”.
                </p>
                <?php wp_nonce_field('alc_nonce', 'alc_nonce_field'); ?>
            </form>

            <div id="alc-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function add_metabox(){
        add_meta_box('alc_mb','Dettagli iscrizione',[$this,'mb_html'], self::CPT,'normal','default');
    }
    public function mb_html($post){
        $meta = get_post_meta($post->ID);
        echo '<style>.alc-mb table{width:100%}.alc-mb th{text-align:left;width:220px}pre{white-space:pre-wrap}</style>';
        echo '<div class="alc-mb"><table class="form-table">';
        $fields = ['nome','cognome','email','telefono','nascita','indirizzo','citta','cap','paese','categorie','totale','currency','per_categoria'];
        foreach($fields as $f){
            $v = isset($meta[$f][0]) ? $meta[$f][0] : '';
            if ($f==='per_categoria'){ $v = '<pre>'.esc_html($v).'</pre>'; } else { $v = '<code>'.esc_html($v).'</code>'; }
            echo "<tr><th>$f</th><td>$v</td></tr>";
        }
        echo '</table></div>';
    }

    /* ===== Helpers ===== */
    private function zip_available(){ return class_exists('ZipArchive'); }
    private function ensure_majorenne($date){
        $dob = strtotime($date);
        if (!$dob) return false;
        $now = new DateTime();
        $birth = DateTime::createFromFormat('Y-m-d', date('Y-m-d',$dob));
        $birth->setDate((int)$now->format('Y'), (int)$birth->format('m'), (int)$birth->format('d'));
        $age = (int)$now->format('Y') - (int)date('Y',$dob);
        if ($birth > $now) $age--;
        return $age >= 18;
    }
    private function count_chars_with_spaces($file_path, $ext){
        $ext = strtolower($ext);
        if ($ext === 'txt'){
            $txt = file_get_contents($file_path);
            return mb_strlen($txt, 'UTF-8');
        } elseif ($ext === 'docx'){
            if (!$this->zip_available()){ return 0; }
            $zip = new ZipArchive();
            if ($zip->open($file_path) === true){
                $i = $zip->locateName('word/document.xml');
                if ($i !== false){
                    $xml = $zip->getFromIndex($i);
                    $text = wp_strip_all_tags($xml);
                    $zip->close();
                    return mb_strlen($text, 'UTF-8');
                }
                $zip->close();
            }
            return 0;
        } elseif ($ext === 'doc'){
            $content = file_get_contents($file_path);
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
            $text = preg_replace('/[\x00]{2,}/', '', $text);
            $text = preg_replace('/[^\P{C}]+/u','',$text);
            $text = trim($text);
            return mb_strlen($text, 'UTF-8');
        }
        return 0;
    }
    private function count_poetry_lines($file_path, $ext){
        $ext = strtolower($ext);
        $text = '';
        if ($ext === 'txt'){
            $text = file_get_contents($file_path);
        } elseif ($ext === 'docx'){
            if (!$this->zip_available()){ return 0; }
            $zip = new ZipArchive();
            if ($zip->open($file_path) === true){
                $i = $zip->locateName('word/document.xml');
                if ($i !== false){
                    $xml = $zip->getFromIndex($i);
                    $text = preg_replace('/<\/w:p>/', "\n", $xml);
                    $text = wp_strip_all_tags($text);
                }
                $zip->close();
            }
        } elseif ($ext === 'doc'){
            $content = file_get_contents($file_path);
            $text = preg_replace('/[\x00-\x1F]+/', "\n", $content);
        }
        $lines = array_filter(array_map('trim', preg_split("/\r?\n/", $text)));
        return count($lines);
    }
    private function process_uploaded_file($file_array){
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = ['test_form' => false, 'mimes' => [
            'txt'=>'text/plain','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ]];
        $move = wp_handle_upload($file_array, $overrides);
        if (!empty($move['error'])){ return [false, $move['error'], null]; }
        return [true, 'OK', $move];
    }
    private function upload_to_drive($local_path, $filename){
        $opt = get_option(self::OPT);
        if (empty($opt['drive_enabled'])) return [false, 'Drive disabilitato', null];
        $client_id = $opt['drive_client_id'] ?? '';
        $client_secret = $opt['drive_client_secret'] ?? '';
        $refresh_token = $opt['drive_refresh_token'] ?? '';
        $folder_id = $opt['drive_folder_id'] ?? '';
        if (!$client_id || !$client_secret || !$refresh_token || !$folder_id){
            return [false, 'Credenziali Drive mancanti', null];
        }
        $response = wp_remote_post('https://oauth2.googleapis.com/token',[
            'body' => [
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token'
            ]
        ]);
        if (is_wp_error($response)) return [false, $response->get_error_message(), null];
        $token = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($token['access_token'])) return [false, 'Access token non ottenuto', null];
        $access = $token['access_token'];
        $boundary = wp_generate_password(24,false);
        $meta = json_encode(['name'=>$filename,'parents'=>[$folder_id]]);
        $file_data = file_get_contents($local_path);
        $payload =
            "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n" + 
            "--$boundary\r\nContent-Type: application/octet-stream\r\n\r\n"+$file_data+"\r\n--$boundary--";

        $res = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',[
            'headers' => ['Authorization' => 'Bearer '.$access, 'Content-Type' => 'multipart/related; boundary='.$boundary],
            'body' => $payload, 'timeout' => 45
        ]);
        if (is_wp_error($res)) return [false, $res->get_error_message(), null];
        $file = json_decode(wp_remote_retrieve_body($res), true);
        if (empty($file['id'])) return [false, 'Upload fallito', null];
        $file_id = $file['id'];
        wp_remote_post("https://www.googleapis.com/drive/v3/files/$file_id/permissions",[
            'headers' => ['Authorization'=>'Bearer '.$access,'Content-Type'=>'application/json'],
            'body' => json_encode(['role'=>'reader','type'=>'anyone']), 'timeout' => 20
        ]);
        $link = "https://drive.google.com/file/d/$file_id/view";
        return [true, 'OK', $link];
    }

    public function handle_verify(){
        check_ajax_referer('alc_nonce','security');
        $resp = ['ok'=>false, 'errors'=>[], 'per_category'=>[]];
        $categorie = isset($_POST['categorie']) && is_array($_POST['categorie']) ? array_map('sanitize_text_field', $_POST['categorie']) : [];
        if (!$categorie){ $resp['errors'][] = 'Seleziona almeno una categoria.'; wp_send_json($resp, 400); }
        foreach($categorie as $cat){
            $key = 'manoscritto_'.$cat;
            if (empty($_FILES[$key]) || $_FILES[$key]['error'] !== UPLOAD_ERR_OK){ $resp['errors'][] = "Carica il manoscritto per la categoria: $cat."; continue; }
            list($ok,$msg,$move) = $this->process_uploaded_file($_FILES[$key]);
            if (!$ok){ $resp['errors'][] = $msg; continue; }
            $file_path = $move['file']; $file_url = $move['url'];
            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            $chars = $this->count_chars_with_spaces($file_path, $ext);
            $versi = ($cat === 'poesia_inedita') ? $this->count_poetry_lines($file_path, $ext) : 0;
            $resp['per_category'][$cat] = [
                'file_url'=>$file_url,
                'char_count'=>$chars,
                'versi'=>$versi
            ];
        }
        if (!empty($resp['errors'])){ wp_send_json($resp, 400); }
        $resp['ok'] = true; wp_send_json($resp);
    }
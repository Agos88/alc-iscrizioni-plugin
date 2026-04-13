<?php
if ( ! defined('ABSPATH') ) { exit; }
if ( ! class_exists('ALC_Plugin') ):
class ALC_Plugin {
    const CPT = 'alc_iscrizione';
    const OPT = 'alc_settings';

    public static function activate(){
        self::register_cpt(); flush_rewrite_rules();
        if (!get_option(self::OPT)){
            add_option(self::OPT, [
                'currency'=>'EUR',
                'fees'=>['romanzo_inedito'=>25.00,'romanzo_edito'=>25.00,'racconto_inedito'=>20.00,'poesia_inedita'=>15.00],
                'max_file_mb'=>10,
                'paypal_enabled'=>0,'paypal_mode'=>'sandbox','paypal_client_id'=>'','paypal_client_secret'=>'','paypal_capture_required'=>1,
                'privacy_url'=>'/privacy-policy',
                'regolamento_url'=>'/regolamento',
                'success_redirect_url'=>'/'
            ]);
        }
    }
    public static function deactivate(){ flush_rewrite_rules(); }

    public function __construct(){
        add_action('init',[__CLASS__,'register_cpt']);
        add_action('admin_menu',[$this,'admin_menu']);
        add_action('admin_init',[$this,'register_settings']);
        add_shortcode('alc_submission_form',[$this,'render_form']);
        add_action('wp_enqueue_scripts',[$this,'assets']);

        add_action('wp_ajax_alc_submit',[$this,'handle_submit']);
        add_action('wp_ajax_nopriv_alc_submit',[$this,'handle_submit']);
        add_action('wp_ajax_alc_export_csv',[$this,'export_csv']);

        add_action('wp_ajax_alc_paypal_create_order',[$this,'paypal_create_order']);
        add_action('wp_ajax_nopriv_alc_paypal_create_order',[$this,'paypal_create_order']);
        add_action('wp_ajax_alc_paypal_capture_order',[$this,'paypal_capture_order']);
        add_action('wp_ajax_nopriv_alc_paypal_capture_order',[$this,'paypal_capture_order']);

        add_action('add_meta_boxes',[$this,'add_metabox']);
    }

    public static function register_cpt(){
        register_post_type(self::CPT,[
            'label'=>'Iscrizioni ALC',
            'public'=>false,
            'show_ui'=>true,
            'menu_icon'=>'dashicons-welcome-write-blog',
            'supports'=>['title']
        ]);
    }

    public function admin_menu(){
        add_menu_page('ALC Iscrizioni','ALC Iscrizioni','manage_options','alc-iscrizioni',[$this,'settings_page'],'dashicons-book',56);
    }

    public function register_settings(){
        register_setting(self::OPT,self::OPT);
        add_settings_section('alc_main','Impostazioni principali',function(){ echo '<p>Configura quote, valuta, dimensione massima file e pagamento.</p>'; }, self::OPT);
        add_settings_field('currency','Valuta',function(){ $o=get_option(self::OPT); printf('<input name="%s[currency]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['currency']??'EUR')); }, self::OPT,'alc_main');
        add_settings_field('fees','Quote per categoria',function(){ $o=get_option(self::OPT); $fees=$o['fees']??[]; $labels=['romanzo_inedito'=>'Romanzo inedito','romanzo_edito'=>'Romanzo edito','racconto_inedito'=>'Racconto inedito','poesia_inedita'=>'Componimento poetico inedito']; echo '<table class="form-table"><tbody>'; foreach($labels as $k=>$lab){ $v=isset($fees[$k])?floatval($fees[$k]):0; printf('<tr><th>%s</th><td><input type="number" step="0.01" name="%s[fees][%s]" value="%s"/></td></tr>', esc_html($lab), self::OPT, esc_attr($k), esc_attr($v)); } echo '</tbody></table>'; }, self::OPT,'alc_main');
        add_settings_field('max_file_mb','Dimensione massima file (MB)',function(){ $o=get_option(self::OPT); $v=isset($o['max_file_mb'])?intval($o['max_file_mb']):10; printf('<input type="number" min="1" max="100" name="%s[max_file_mb]" value="%d" />', self::OPT, $v); }, self::OPT,'alc_main');

        add_settings_section('alc_paypal','Pagamento - PayPal',function(){ echo '<p>Abilita il bottone “Paga adesso” con PayPal (Checkout). Il totale è calcolato dal server.</p>'; }, self::OPT);
        add_settings_field('paypal_enabled','Abilita PayPal',function(){ $o=get_option(self::OPT); $chk=!empty($o['paypal_enabled'])?'checked':''; printf('<label><input type="checkbox" name="%s[paypal_enabled]" value="1" %s/> Mostra “Paga adesso”</label>', self::OPT, $chk); }, self::OPT,'alc_paypal');
        add_settings_field('paypal_mode','Modalità',function(){ $o=get_option(self::OPT); $mode=$o['paypal_mode']??'sandbox'; echo '<select name="'.self::OPT.'[paypal_mode]">'; echo '<option value="sandbox"'.selected($mode,'sandbox',false).'>Sandbox</option>'; echo '<option value="live"'.selected($mode,'live',false).'>Live</option>'; echo '</select>'; }, self::OPT,'alc_paypal');
        add_settings_field('paypal_client_id','Client ID',function(){ $o=get_option(self::OPT); printf('<input name="%s[paypal_client_id]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['paypal_client_id']??'')); }, self::OPT,'alc_paypal');
        add_settings_field('paypal_client_secret','Client Secret',function(){ $o=get_option(self::OPT); printf('<input type="password" name="%s[paypal_client_secret]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['paypal_client_secret']??'')); }, self::OPT,'alc_paypal');
        add_settings_field('paypal_capture_required','Richiedi pagamento prima dell&#39;invio',function(){ $o=get_option(self::OPT); $chk=!empty($o['paypal_capture_required'])?'checked':''; printf('<label><input type="checkbox" name="%s[paypal_capture_required]" value="1" %s/> Blocca l&#39;invio finché il pagamento non è catturato</label>', self::OPT, $chk); }, self::OPT,'alc_paypal');

        add_settings_section('alc_links','Link e Redirect',function(){ echo '<p>Imposta i link mostrati nel form e il redirect nella conferma.</p>'; }, self::OPT);
        add_settings_field('privacy_url','Link informativa privacy',function(){ $o=get_option(self::OPT); printf('<input name="%s[privacy_url]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['privacy_url']??'/privacy-policy')); }, self::OPT,'alc_links');
        add_settings_field('regolamento_url','Link regolamento',function(){ $o=get_option(self::OPT); printf('<input name="%s[regolamento_url]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['regolamento_url']??'/regolamento')); }, self::OPT,'alc_links');
        add_settings_field('success_redirect_url','Link bottone “Torna alla Home”',function(){ $o=get_option(self::OPT); printf('<input name="%s[success_redirect_url]" value="%s" class="regular-text" />', self::OPT, esc_attr($o['success_redirect_url']??'/')); }, self::OPT,'alc_links');
    }

    public function settings_page(){ ?>
        <div class="wrap">
            <h1>Agitazioni Letterarie Castelluccesi — Iscrizioni</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); do_settings_sections(self::OPT); submit_button(); ?>
            </form>

            <h2>Esportazione CSV</h2>
            <form method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
                <input type="hidden" name="action" value="alc_export_csv" />
                <?php wp_nonce_field('alc_export_csv','alc_export_csv_nonce'); ?>
                <label>Categoria
                    <select name="cat">
                        <option value="">Tutte</option>
                        <option value="romanzo_inedito">Romanzo inedito</option>
                        <option value="romanzo_edito">Romanzo edito</option>
                        <option value="racconto_inedito">Racconto inedito</option>
                        <option value="poesia_inedita">Componimento poetico inedito</option>
                    </select>
                </label>
                <button type="submit" class="button button-primary">Scarica CSV</button>
            </form>

            <h2>Shortcode</h2>
            <p>Usa <code>[alc_submission_form]</code> nella pagina iscrizioni.</p>
        </div>
    <?php }

    public function assets(){
        $base = trailingslashit(plugins_url('', ALC_PLUGIN_FILE));
        wp_enqueue_style('alc-style', $base.'assets/css/style.css', [], ALC_VERSION);
        wp_enqueue_script('alc-form', $base.'assets/js/form.js', ['jquery'], ALC_VERSION, true);
        $o = get_option(self::OPT);
        wp_localize_script('alc-form','ALC_VARS',[
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('alc_nonce'),
            'fees'=>$o['fees']??[],
            'currency'=>$o['currency']??'EUR',
            'max_file_mb'=> isset($o['max_file_mb'])?intval($o['max_file_mb']):10,
            'privacy_url'=>$o['privacy_url']??'/privacy-policy',
            'regolamento_url'=>$o['regolamento_url']??'/regolamento',
            'success_url'=>$o['success_redirect_url']??'/'
        ]);
        wp_localize_script('alc-form','ALC_PAYPAL',[
            'enabled'          => !empty($o['paypal_enabled']),
            'mode'             => $o['paypal_mode'] ?? 'sandbox',
            'capture_required' => !empty($o['paypal_capture_required']),
        ]);
        if (!empty($o['paypal_client_id'])){
            $sdk='https://www.paypal.com/sdk/js?client-id='.rawurlencode($o['paypal_client_id']).'&currency='.rawurlencode($o['currency']??'EUR').'&intent=capture&components=buttons';
            wp_enqueue_script('paypal-sdk', $sdk, [], null, true);
        }
    }

    public function render_form(){
        $o = get_option(self::OPT);
        $privacy = esc_url($o['privacy_url'] ?? '/privacy-policy');
        $regolamento = esc_url($o['regolamento_url'] ?? '/regolamento');
        ob_start(); ?>
        <div class="alc-wrapper">
            <h3>Iscrizione al Concorso “Agitazioni Letterarie Castelluccesi”</h3>
            <form id="alc-form" enctype="multipart/form-data">
                <fieldset><legend>1. Dati anagrafici</legend>
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
                </div></fieldset>

                <fieldset><legend>2. Categorie e manoscritti</legend>
                    <p>Seleziona una o più categorie: per ciascuna, si aprirà un pannello dove inserire il <strong>titolo</strong> e <strong>caricare il manoscritto</strong> (PDF, DOC, DOCX o TXT).</p>
                    <div class="alc-cats">
                        <label><input type="checkbox" name="categorie[]" value="romanzo_inedito"> Romanzo inedito (non incluso in opera provvista di codice ISBN)</label>
                        <div class="alc-panel" data-panel="romanzo_inedito" style="display:none"></div>

                        <label><input type="checkbox" name="categorie[]" value="romanzo_edito"> Romanzo edito</label>
                        <div class="alc-panel" data-panel="romanzo_edito" style="display:none"></div>

                        <label><input type="checkbox" name="categorie[]" value="racconto_inedito"> Racconto inedito (non incluso in opera provvista di codice ISBN)</label>
                        <div class="alc-panel" data-panel="racconto_inedito" style="display:none"></div>

                        <label><input type="checkbox" name="categorie[]" value="poesia_inedita"> Componimento poetico inedito (non incluso in opera provvista di codice ISBN)</label>
                        <div class="alc-panel" data-panel="poesia_inedita" style="display:none"></div>
                    </div>
                </fieldset>

                <div class="alc-fee"><strong>Quota totale:</strong> <span id="alc-total">0</span> <span id="alc-currency"></span></div>

                <div class="alc-consents">
                  <label class="alc-consent">
                    <input type="checkbox" name="privacy" id="alc-privacy" required> Ho letto e accetto l'<a href="<?php echo $privacy; ?>" target="_blank" rel="noopener" id="alc-privacy-link">informativa privacy</a>.
                  </label>
                  <label class="alc-consent">
                    <input type="checkbox" name="regolamento" id="alc-regolamento" required> Ho letto e accetto il <a href="<?php echo $regolamento; ?>" target="_blank" rel="noopener" id="alc-regolamento-link">regolamento del concorso</a>.
                  </label>
                </div>

                <div id="alc-paypal-wrap" style="margin:10px 0; display:none;">
                    <div id="paypal-button-container"></div>
                    <div class="alc-note">Dopo il pagamento PayPal il modulo si invierà automaticamente.</div>
                </div>

                <input type="hidden" name="submission_id" id="submission_id">
                <input type="hidden" name="paypal_order_id" id="paypal_order_id">
                <input type="hidden" name="paypal_capture_id" id="paypal_capture_id">
                <input type="hidden" name="paypal_payer_email" id="paypal_payer_email">
                <input type="hidden" name="paypal_amount" id="paypal_amount">
                <!-- RIMOSSO: l’invio avviene automaticamente dopo il pagamento PayPal -->
                <div class="alc-actions"><button type="submit" id="alc-submit" disabled>Invia candidatura</button></div>
                <p class="alc-support">Problemi con l'iscrizione? <a href="mailto:info@agitazioniletterariecast.com">Contattaci</a></p>
            </form>
            <div id="alc-result"></div>
        </div>
        <?php return ob_get_clean();
    }

    public function add_metabox(){
        add_meta_box('alc_mb','Dettagli iscrizione',[$this,'mb_html'], self::CPT,'normal','default');
    }
    public function mb_html($post){
        $m = get_post_meta($post->ID);
        echo '<style>.alc-mb table{width:100%}.alc-mb th{text-align:left;width:220px}pre{white-space:pre-wrap}</style>';
        echo '<div class="alc-mb"><table class="form-table">';
        $fields = ['submission_id','nome','cognome','email','telefono','nascita','indirizzo','citta','cap','paese','categorie','totale','currency','per_categoria','paypal_order_id','paypal_capture_id','paypal_payer_email','paypal_amount'];
        foreach($fields as $f){
            $v = isset($m[$f][0]) ? $m[$f][0] : '';
            if ($f==='per_categoria'){ $v = '<pre>'.esc_html($v).'</pre>'; } else { $v = '<code>'.esc_html($v).'</code>'; }
            echo '<tr><th>'.esc_html($f).'</th><td>'.$v.'</td></tr>';
        }
        echo '</table></div>';
    }


    private function alc_storage_dir($submission_id){
        $u = wp_upload_dir();
        $root = trailingslashit($u['basedir']).'alc-iscrizioni/';
        if (!file_exists($root)) wp_mkdir_p($root);
        // Blocca l'accesso diretto via web su server Apache/compatibili
        if (!file_exists($root.'.htaccess'))
            file_put_contents($root.'.htaccess', "Options -Indexes\nDeny from all\n");
        if (!file_exists($root.'index.php'))
            file_put_contents($root.'index.php', '<?php // Silence is golden.');
        $base = $root.sanitize_file_name($submission_id).'/';
        if (!file_exists($base)) wp_mkdir_p($base);
        $url = trailingslashit($u['baseurl']).'alc-iscrizioni/'.rawurlencode(sanitize_file_name($submission_id)).'/';
        return [$base,$url];
    }

    private function validate_upload_mime($tmp_path, $ext){
        $handle = @fopen($tmp_path,'rb'); if(!$handle) return false;
        $header = fread($handle,8); fclose($handle);
        if($header===false||strlen($header)<4) return false;
        switch($ext){
            case 'pdf':  return strncmp($header,'%PDF-',5)===0;
            case 'doc':  return substr($header,0,4)==="\xD0\xCF\x11\xE0";
            case 'docx': return substr($header,0,4)==="PK\x03\x04";
            case 'txt':
                $content=@file_get_contents($tmp_path,false,null,0,256);
                return $content!==false && !preg_match('/<\?(?:php|=)/i',$content);
            default: return false;
        }
    }

    public function handle_submit(){
        check_ajax_referer('alc_nonce','security');
        $resp=['ok'=>false,'errors'=>[]];
        foreach(['nome','cognome','email','nascita'] as $r){ if(empty($_POST[$r])) $resp['errors'][]='Campo obbligatorio mancante: '.$r; }
        if(empty($_POST['privacy'])) $resp['errors'][]='Accetta la privacy.';
        if(empty($_POST['regolamento'])) $resp['errors'][]='Conferma il regolamento.';
        if($resp['errors']) wp_send_json($resp,400);

        $email=sanitize_email($_POST['email']); if(!is_email($email)){ $resp['errors'][]='Email non valida.'; wp_send_json($resp,400); }
        $allowed=['romanzo_inedito','romanzo_edito','racconto_inedito','poesia_inedita'];
        $cats = isset($_POST['categorie']) && is_array($_POST['categorie']) ? array_values(array_intersect(array_map('sanitize_text_field', $_POST['categorie']), $allowed)) : [];
        if(!$cats){ $resp['errors'][]='Seleziona almeno una categoria.'; wp_send_json($resp,400); }

        $o=get_option(self::OPT);
        if(!empty($o['paypal_enabled']) && !empty($o['paypal_capture_required'])){
            $order_id=sanitize_text_field($_POST['paypal_order_id']??'');
            if(!$order_id){ $resp['errors'][]='Completa il pagamento PayPal prima di inviare.'; wp_send_json($resp,400); }
            // Verifica server-side con l'API PayPal che l'ordine sia realmente COMPLETED
            $api_base=$this->paypal_api_base();
            $tok=wp_remote_post("$api_base/v1/oauth2/token",[
                'headers'=>['Authorization'=>$this->paypal_auth_header()],
                'body'=>['grant_type'=>'client_credentials'],'timeout'=>30
            ]);
            $t=!is_wp_error($tok)?json_decode(wp_remote_retrieve_body($tok),true):[];
            if(empty($t['access_token'])){ $resp['errors'][]='Verifica pagamento non riuscita.'; wp_send_json($resp,400); }
            $chk=wp_remote_get($api_base.'/v2/checkout/orders/'.rawurlencode($order_id),[
                'headers'=>['Authorization'=>'Bearer '.$t['access_token']],'timeout'=>30
            ]);
            $order_data=!is_wp_error($chk)?json_decode(wp_remote_retrieve_body($chk),true):[];
            if(($order_data['status']??'')!=='COMPLETED'){ $resp['errors'][]='Pagamento PayPal non verificato o non completato.'; wp_send_json($resp,400); }
            // Verifica che l'importo corrisponda al totale atteso calcolato server-side
            $fees_chk=$o['fees']??[]; $expected=0.0;
            foreach($cats as $c){ $expected+=isset($fees_chk[$c])?floatval($fees_chk[$c]):0.0; }
            $paid_amount=$order_data['purchase_units'][0]['payments']['captures'][0]['amount']['value']??null;
            if($paid_amount!==null && $paid_amount!==number_format($expected,2,'.','')){
                $resp['errors'][]='Importo pagato non corrispondente al totale atteso.'; wp_send_json($resp,400);
            }
        }

        $submission_id = sanitize_text_field($_POST['submission_id'] ?? '');
        if (empty($submission_id)){
            if (!empty($_POST['paypal_order_id'])) $submission_id = sanitize_text_field($_POST['paypal_order_id']);
            else $submission_id = 'ALC-'.wp_generate_password(10,false,false);
        }

        $nome=sanitize_text_field($_POST['nome']); $cognome=sanitize_text_field($_POST['cognome']); $telefono=sanitize_text_field($_POST['telefono']??''); $nascita=sanitize_text_field($_POST['nascita']);
        $indirizzo=sanitize_text_field($_POST['indirizzo']??''); $citta=sanitize_text_field($_POST['citta']??''); $cap=sanitize_text_field($_POST['cap']??''); $paese=sanitize_text_field($_POST['paese']??'');

        $post_id=wp_insert_post(['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$submission_id.' — '.$cognome.' '.$nome,'post_content'=>'Iscrizione generata dal modulo ALC.']);
        if(is_wp_error($post_id)||!$post_id){ $resp['errors'][]='Errore nel salvataggio.'; wp_send_json($resp,500); }

        $results=[]; $max_mb=isset($o['max_file_mb'])?max(1,intval($o['max_file_mb'])):10; $max_bytes=$max_mb*1024*1024;
        $allowed_ext=['pdf','doc','docx','txt'];
        list($dir,$urlbase) = $this->alc_storage_dir($submission_id);

        foreach($cats as $cat){
            $tkey='titolo_'.$cat; $fkey='manoscritto_'.$cat;
            if(empty($_POST[$tkey])){ $resp['errors'][]='Titolo mancante per '.$cat; continue; }
            if(empty($_FILES[$fkey])||$_FILES[$fkey]['error']!==UPLOAD_ERR_OK){ $resp['errors'][]='File non caricato per '.$cat; continue; }

            $fname=sanitize_file_name($_FILES[$fkey]['name']);
            $ext=strtolower(pathinfo($fname,PATHINFO_EXTENSION));
            $size=intval($_FILES[$fkey]['size']);
            if(!in_array($ext,$allowed_ext,true)){ $resp['errors'][]='Estensione non ammessa per '.$cat.' (consentiti: PDF, DOC, DOCX, TXT)'; continue; }
            if(!$this->validate_upload_mime($_FILES[$fkey]['tmp_name'],$ext)){ $resp['errors'][]='Tipo di file non valido per '.$cat.'.'; continue; }
            if($size<=0 || $size>$max_bytes){ $resp['errors'][]='Il file per '.$cat.' supera '.$max_mb.'MB'; continue; }

            // Costruisci nome file: submission_id_categoria_nome_cognome.ext
            $safe_nome = sanitize_title($nome);
            $safe_cognome = sanitize_title($cognome);
            $new_name = sprintf('%s_%s_%s_%s.%s', $submission_id, $cat, $safe_nome, $safe_cognome, $ext);

            // Evita collisioni: se esiste già, aggiungi -1, -2, ...
            $dest_path = trailingslashit($dir).$new_name;
            if (file_exists($dest_path)) {
                $i = 1;
                do {
                    $new_name_try = sprintf('%s_%s_%s_%s-%d.%s', $submission_id, $cat, $safe_nome, $safe_cognome, $i, $ext);
                    $dest_path = trailingslashit($dir) . $new_name_try;
                    $i++;
                } while (file_exists($dest_path));
                $new_name = basename($dest_path);
            }

            // Sposta direttamente dal tmp PHP alla cartella definitiva, senza passare per wp_handle_upload
            // (evita problemi di rilevamento MIME per docx/doc che PHP identifica come application/zip)
            if (!move_uploaded_file($_FILES[$fkey]['tmp_name'], $dest_path)){
                $resp['errors'][]='Impossibile salvare il file per '.$cat; continue;
            }
            $file_url = $urlbase . rawurlencode($new_name);

            $results[$cat]=[
                'titolo'=>sanitize_text_field($_POST[$tkey]),
                'file_path'=>$dest_path,
                'file_url'=>$file_url,
                'file_size'=> (file_exists($dest_path)?filesize($dest_path):0)
            ];
        }

        if($resp['errors']) wp_send_json($resp,400);

        $fees=$o['fees']??[]; $currency=$o['currency']??'EUR'; $total=0.0; foreach($cats as $c){ $total+=isset($fees[$c])?floatval($fees[$c]):0.0; }

        $meta=[
            'submission_id'=>$submission_id,
            'nome'=>$nome,'cognome'=>$cognome,'email'=>$email,'telefono'=>$telefono,'nascita'=>$nascita,
            'indirizzo'=>$indirizzo,'citta'=>$citta,'cap'=>$cap,'paese'=>$paese,
            'categorie'=>implode(',',$cats),'totale'=>number_format($total,2,'.',''),'currency'=>$currency,
            'per_categoria'=> wp_json_encode($results, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'paypal_order_id'=>sanitize_text_field($_POST['paypal_order_id']??''),
            'paypal_capture_id'=>sanitize_text_field($_POST['paypal_capture_id']??''),
            'paypal_payer_email'=>sanitize_email($_POST['paypal_payer_email']??''),
            'paypal_amount'=>sanitize_text_field($_POST['paypal_amount']??''),
        ];
        foreach($meta as $k=>$v){ update_post_meta($post_id,$k,$v); }

        wp_send_json(['ok'=>true,'post_id'=>$post_id,'submission_id'=>$submission_id,'total'=>$total,'currency'=>$currency,'per_category'=>$results]);
    }

    public function export_csv(){
        if(!current_user_can('manage_options')) wp_die('Permesso negato');
        if(empty($_POST['alc_export_csv_nonce']) || !wp_verify_nonce($_POST['alc_export_csv_nonce'],'alc_export_csv')) wp_die('Nonce non valido');
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="iscrizioni_alc.csv"');
        $out=fopen('php://output','w');
        fputcsv($out,['ID','SubmissionID','Data','Nome','Cognome','Email','Telefono','Nascita','Indirizzo','Città','CAP','Paese','Categorie','Totale','Valuta','Dettagli','PayPal Order','PayPal Capture','PayPal Email','PayPal Importo']);
        $q=new WP_Query(['post_type'=>self::CPT,'posts_per_page'=>-1,'post_status'=>'publish']);
        while($q->have_posts()){ $q->the_post();
            $id=get_the_ID(); $m=get_post_meta($id); $per=isset($m['per_categoria'][0])?json_decode($m['per_categoria'][0],true):[];
            $details=[];
            if(is_array($per)){
                foreach($per as $cat=>$d){
                    $titolo = isset($d['titolo']) ? $d['titolo'] : '';
                    $bytes  = isset($d['file_size']) ? $d['file_size'] : 0;
                    $details[] = $cat.' | Titolo: '.$titolo.' | Bytes: '.$bytes;
                }
            }
            fputcsv($out,[
                $id, $m['submission_id'][0]??'',
                get_the_time('Y-m-d H:i:s'),
                $m['nome'][0]??'', $m['cognome'][0]??'', $m['email'][0]??'', $m['telefono'][0]??'',
                $m['nascita'][0]??'', $m['indirizzo'][0]??'', $m['citta'][0]??'', $m['cap'][0]??'', $m['paese'][0]??'',
                $m['categorie'][0]??'', $m['totale'][0]??'', $m['currency'][0]??'',
                implode(' || ',$details),
                $m['paypal_order_id'][0]??'', $m['paypal_capture_id'][0]??'', $m['paypal_payer_email'][0]??'', $m['paypal_amount'][0]??''
            ]);
        }
        wp_reset_postdata(); fclose($out); exit;
    }

    private function paypal_api_base(){ $o=get_option(self::OPT); $m=$o['paypal_mode']??'sandbox'; return ($m==='live')?'https://api-m.paypal.com':'https://api-m.sandbox.paypal.com'; }
    private function paypal_auth_header(){ $o=get_option(self::OPT); $cid=trim($o['paypal_client_id']??''); $sec=trim($o['paypal_client_secret']??''); return 'Basic '.base64_encode($cid.':'.$sec); }

    public function paypal_create_order(){
        check_ajax_referer('alc_nonce','security');
        $o=get_option(self::OPT);
        if(empty($o['paypal_enabled'])||empty($o['paypal_client_id'])||empty($o['paypal_client_secret'])) wp_send_json(['ok'=>false,'error'=>'PayPal non configurato'],400);
        $allowed=['romanzo_inedito','romanzo_edito','racconto_inedito','poesia_inedita']; $fees=$o['fees']??[]; $currency=$o['currency']??'EUR';
        $sel=isset($_POST['categorie']) && is_array($_POST['categorie']) ? array_values(array_intersect(array_map('sanitize_text_field', $_POST['categorie']), $allowed)) : [];
        if(!$sel) wp_send_json(['ok'=>false,'error'=>'Nessuna categoria selezionata'],400);
        $total=0.0; foreach($sel as $c){ $total+=isset($fees[$c])?floatval($fees[$c]):0.0; } $total=number_format($total,2,'.','');

        $base=$this->paypal_api_base();
        $tok=wp_remote_post("$base/v1/oauth2/token",['headers'=>['Authorization'=>$this->paypal_auth_header()],'body'=>['grant_type'=>'client_credentials'],'timeout'=>30]);
        if(is_wp_error($tok)) wp_send_json(['ok'=>false,'error'=>$tok->get_error_message()],400);
        $t=json_decode(wp_remote_retrieve_body($tok),true); if(empty($t['access_token'])) wp_send_json(['ok'=>false,'error'=>'Access token non ottenuto'],400);

        $provisional = 'ALC-'.wp_generate_password(10,false,false);
        $order=['intent'=>'CAPTURE','purchase_units'=>[[
            'amount'=>['currency_code'=>$currency,'value'=>$total],
            'description'=>'Iscrizione Concorso ALC',
            'invoice_id'=>$provisional
        ]]];
        $res=wp_remote_post("$base/v2/checkout/orders",['headers'=>['Authorization'=>'Bearer '.$t['access_token'],'Content-Type'=>'application/json'],'body'=>wp_json_encode($order),'timeout'=>30]);
        if(is_wp_error($res)) wp_send_json(['ok'=>false,'error'=>$res->get_error_message()],400);
        $ojs=json_decode(wp_remote_retrieve_body($res),true); if(empty($ojs['id'])) wp_send_json(['ok'=>false,'error'=>'Creazione ordine fallita'],400);
        wp_send_json(['ok'=>true,'orderID'=>$ojs['id'],'amount'=>$total,'currency'=>$currency,'provisional_submission_id'=>$provisional]);
    }

    public function paypal_capture_order(){
        check_ajax_referer('alc_nonce','security');
        $id=sanitize_text_field($_POST['orderID']??''); if(!$id) wp_send_json(['ok'=>false,'error'=>'orderID mancante'],400);
        $o=get_option(self::OPT); if(empty($o['paypal_enabled'])||empty($o['paypal_client_id'])||empty($o['paypal_client_secret'])) wp_send_json(['ok'=>false,'error'=>'PayPal non configurato'],400);
        $base=$this->paypal_api_base();
        $tok=wp_remote_post("$base/v1/oauth2/token",['headers'=>['Authorization'=>$this->paypal_auth_header()],'body'=>['grant_type'=>'client_credentials'],'timeout'=>30]);
        if(is_wp_error($tok)) wp_send_json(['ok'=>false,'error'=>$tok->get_error_message()],400);
        $t=json_decode(wp_remote_retrieve_body($tok),true); if(empty($t['access_token'])) wp_send_json(['ok'=>false,'error'=>'Access token non ottenuto'],400);
        $cap=wp_remote_post("$base/v2/checkout/orders/$id/capture",['headers'=>['Authorization'=>'Bearer '.$t['access_token'],'Content-Type'=>'application/json'],'timeout'=>45]);
        if(is_wp_error($cap)) wp_send_json(['ok'=>false,'error'=>$cap->get_error_message()],400);
        $d=json_decode(wp_remote_retrieve_body($cap),true);
        $status=$d['status']??''; $payer_email=$d['payer']['email_address']??''; $capture_id=''; $amount=''; $currency=''; $invoice_id='';
        if(!empty($d['purchase_units'][0])){
            $pu=$d['purchase_units'][0];
            $invoice_id = $pu['invoice_id'] ?? '';
            if(!empty($pu['payments']['captures'][0])){
                $c=$pu['payments']['captures'][0]; $capture_id=$c['id']??''; $amount=$c['amount']['value']??''; $currency=$c['amount']['currency_code']??'';
            }
        }
        if($status!=='COMPLETED' && $status!=='APPROVED') wp_send_json(['ok'=>false,'error'=>'Pagamento non completato'],400);
        wp_send_json(['ok'=>true,'status'=>$status,'orderID'=>$id,'invoice_id'=>$invoice_id,'captureID'=>$capture_id,'payer_email'=>$payer_email,'amount'=>$amount,'currency'=>$currency]);
    }
}
endif;

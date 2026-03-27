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

        add_action('wp_ajax_alc_verify',[$this,'handle_verify']);
        add_action('wp_ajax_nopriv_alc_verify',[$this,'handle_verify']);
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
            'enabled'=>!empty($o['paypal_enabled']),
            'mode'=>$o['paypal_mode']??'sandbox'
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
                    <p>Seleziona una o più categorie: per ciascuna, si aprirà un pannello dove inserire il <strong>titolo</strong> e <strong>caricare il manoscritto</strong>. Puoi anche verificare il conteggio dei caratteri (spazi inclusi).</p>
					<div class="alc-info alc-note-important">
					  <strong>Nota importante:</strong> la funzione “Verifica manoscritto” serve solo per un controllo indicativo.
					  <br>Ricorda che i <strong>limiti di caratteri previsti dal bando devono essere sempre rispettati</strong>.
					  <br>L’organizzazione effettuerà una verifica ufficiale dopo l’invio dell’iscrizione.
					  <br><em>La quota di iscrizione non verrà rimborsata nel caso in cui non siano rispettati i requisiti indicati dal bando.</em>
					</div>
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

    private function zip_available(){ return class_exists('ZipArchive'); }
    private function safe_strlen($s){ return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s); }

    private function count_chars_with_spaces($path,$ext){
        $ext=strtolower($ext);
        if($ext==='txt'){ $t=@file_get_contents($path); return $this->safe_strlen($t?:''); }
        if($ext==='docx'){ if(!$this->zip_available()) return 0; $z=new ZipArchive(); if($z->open($path)===true){ $i=$z->locateName('word/document.xml'); if($i!==false){ $xml=$z->getFromIndex($i); $text=wp_strip_all_tags($xml); $z->close(); return $this->safe_strlen($text);} $z->close(); } return 0; }
        if($ext==='doc'){ $b=@file_get_contents($path)?:''; $text=preg_replace('/[\x00-\x1F]+/',' ',$b); return $this->safe_strlen(trim($text)); }
        return 0;
    }
    private function count_poetry_lines($path,$ext){
        $ext=strtolower($ext); $text='';
        if($ext==='txt'){ $text=@file_get_contents($path)?:''; }
        elseif($ext==='docx'){ if(!$this->zip_available()) return 0; $z=new ZipArchive(); if($z->open($path)===true){ $i=$z->locateName('word/document.xml'); if($i!==false){ $xml=$z->getFromIndex($i); $xml=preg_replace('/<\/w:p>/',"\n",$xml); $text=wp_strip_all_tags($xml);} $z->close(); } }
        else { $b=@file_get_contents($path)?:''; $text=preg_replace('/[\x00-\x1F]+/',"\n",$b); }
        $lines=array_filter(array_map('trim', preg_split("/\r?\n/", $text)));
        return count($lines);
    }

    private function process_uploaded_file_temp($file){
        require_once ABSPATH.'wp-admin/includes/file.php';
        $overrides=['test_form'=>false,'mimes'=>['txt'=>'text/plain','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
        $move=wp_handle_upload($file,$overrides);
        if(!empty($move['error'])) return [false,$move['error'],null];
        return [true,'OK',$move];
    }

    private function alc_storage_dir($submission_id){
        $u = wp_upload_dir();
        $base = trailingslashit($u['basedir']).'alc-iscrizioni/'.sanitize_file_name($submission_id).'/';
        if (!file_exists($base)) wp_mkdir_p($base);
        $url = trailingslashit($u['baseurl']).'alc-iscrizioni/'.rawurlencode(sanitize_file_name($submission_id)).'/';
        return [$base,$url];
    }

    public function handle_verify(){
        check_ajax_referer('alc_nonce','security');
        $resp=['ok'=>false,'errors'=>[],'per_category'=>[]];
        $allowed=['romanzo_inedito','romanzo_edito','racconto_inedito','poesia_inedita'];
        $cats = isset($_POST['categorie']) && is_array($_POST['categorie']) ? array_values(array_intersect(array_map('sanitize_text_field', $_POST['categorie']), $allowed)) : [];
        if(!$cats){ $resp['errors'][]='Seleziona almeno una categoria valida.'; wp_send_json($resp,400); }
        $processed = 0;
        foreach($cats as $cat){
            $key='manoscritto_'.$cat;
            if(empty($_FILES[$key]) || $_FILES[$key]['error']!==UPLOAD_ERR_OK){ continue; }
            list($ok,$msg,$move)=$this->process_uploaded_file_temp($_FILES[$key]);
            if(!$ok){ $resp['errors'][]=$msg; continue; }
            $processed++;
            $path=$move['file']; $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
            $chars=$this->count_chars_with_spaces($path,$ext);
            $versi=($cat==='poesia_inedita')?$this->count_poetry_lines($path,$ext):0;
            $resp['per_category'][$cat]=['char_count'=>$chars,'versi'=>$versi];
            if($path && file_exists($path)) @unlink($path);
        }
        if($processed===0){ $resp['errors'][]='Nessun file fornito per la verifica.'; wp_send_json($resp,400); }
        if($resp['errors']) wp_send_json($resp,400);
        $resp['ok']=true; wp_send_json($resp);
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
            $paid=!empty($_POST['paypal_order_id']) && (!empty($_POST['paypal_capture_id']) || !empty($_POST['paypal_payer_email']));
            if(!$paid){ $resp['errors'][]='Completa il pagamento PayPal prima di inviare.'; wp_send_json($resp,400); }
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

        require_once ABSPATH.'wp-admin/includes/file.php';
        $results=[]; $max_mb=isset($o['max_file_mb'])?max(1,intval($o['max_file_mb'])):10; $max_bytes=$max_mb*1024*1024; $allowed_ext=['txt','doc','docx'];
        list($dir,$urlbase) = $this->alc_storage_dir($submission_id);

        foreach($cats as $cat){
            $tkey='titolo_'.$cat; $fkey='manoscritto_'.$cat;
            if(empty($_POST[$tkey])){ $resp['errors'][]='Titolo mancante per '.$cat; continue; }
            if(empty($_FILES[$fkey])||$_FILES[$fkey]['error']!==UPLOAD_ERR_OK){ $resp['errors'][]='File non caricato per '.$cat; continue; }

            $fname=sanitize_file_name($_FILES[$fkey]['name']); 
			$ext=strtolower(pathinfo($fname,PATHINFO_EXTENSION)); 
			$size=intval($_FILES[$fkey]['size']);
            if(!in_array($ext,$allowed_ext,true)){ $resp['errors'][]='Estensione non ammessa per '.$cat; continue; }
            if($size<=0 || $size>$max_bytes){ $resp['errors'][]='Il file per '.$cat.' supera '.$max_mb.'MB'; continue; }

            $overrides=['test_form'=>false,'mimes'=>['txt'=>'text/plain','doc'=>'application/msword','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document']];
            $m = wp_handle_upload($_FILES[$fkey], $overrides);
            if (!empty($m['error'])){ $resp['errors'][] = $m['error']; continue; }
            $src_path = $m['file'];
			
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
			
			// Sposta il file fisico nella cartella definitiva
            if (!@rename($src_path, $dest_path)){
                @copy($src_path, $dest_path);
                @unlink($src_path);
            }
            $file_url = $urlbase . rawurlencode($new_name);
			
			// Conteggi
            $chars=$this->count_chars_with_spaces($dest_path,$ext);
            $versi=($cat==='poesia_inedita')?$this->count_poetry_lines($dest_path,$ext):0;

            $results[$cat]=[
                'titolo'=>sanitize_text_field($_POST[$tkey]),
                'file_path'=>$dest_path,
                'file_url'=>$file_url,
                'char_count'=>$chars,
                'versi'=>$versi,
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
                    $chars  = isset($d['char_count']) ? $d['char_count'] : 0;
                    $versi  = ($cat==='poesia_inedita') ? (' | Versi: '.(isset($d['versi'])?$d['versi']:0)) : '';
                    $bytes  = isset($d['file_size']) ? $d['file_size'] : 0;
                    $details[] = $cat.' | Titolo: '.$titolo.' | Caratteri: '.$chars.$versi.' | Bytes: '.$bytes;
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

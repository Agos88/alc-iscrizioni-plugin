jQuery(function($){
  $('#alc-currency').text(ALC_VARS.currency);
  const PANELS={'romanzo_inedito':'Romanzo inedito','romanzo_edito':'Romanzo edito','racconto_edito':'Racconto inedito','poesia_inedita':'Componimento poetico inedito'};
  function panelMarkup(cat,label){
    const poetry=(cat==='poesia_inedita');
    return '<div class="alc-panel-inner">'
      +'<h4>'+label+'</h4>'
      +'<label>Titolo del manoscritto *<input type="text" name="titolo_'+cat+'" required></label>'
      +'<label>Carica manoscritto (.txt, .doc, .docx) *<input type="file" name="manoscritto_'+cat+'" accept=".txt,.doc,.docx" required></label>'
      +'<div class="alc-verify"><button type="button" class="button alc-verify-btn" data-cat="'+cat+'">Verifica manoscritto</button>'
      +'<span class="alc-verify-note">Il conteggio considera tutti i caratteri, spazi inclusi.'+(poetry?' Per la poesia si contano anche i versi.':'')+'</span>'
      +'<div class="alc-verify-out" id="verify_'+cat+'"></div></div>'
      +'</div>';
  }
  function togglePanel(cat, checked){
    const holder = $('.alc-panel[data-panel="'+cat+'"]');
    if(checked){
      if(!holder.data('rendered')){
        holder.html(panelMarkup(cat, PANELS[cat]));
        holder.data('rendered', true);
      }
      holder.slideDown(150);
    } else {
      holder.slideUp(150);
    }
    computeTotal();
  }
  function computeTotal(){ let t=0, fees=ALC_VARS.fees||{}; $('input[name="categorie[]"]:checked').each(function(){ t+=parseFloat(fees[$(this).val()]||0); }); $('#alc-total').text(t.toFixed(2)); $('#alc-currency').text(ALC_VARS.currency); validateSubmit(); }
  function validateSubmit(){
    let ok=true;
    $('#alc-form [required]').each(function(){
      const $el=$(this);
      if($el.is(':hidden')) return;
      if(!$el.val()){ ok=false; return false; }
    });
    $('input[name="categorie[]"]:checked').each(function(){
      const c=$(this).val();
      if(!$('input[name="titolo_'+c+'"]').val()) ok=false;
      if(!$('input[name="manoscritto_'+c+'"]').val()) ok=false;
    });
    if(!$('#alc-privacy').is(':checked')) ok=false;
    if(!$('#alc-regolamento').is(':checked')) ok=false;
    $('#alc-submit').prop('disabled', !ok);
    return ok;
  }
  $(document).on('change','input[name="categorie[]"]', function(){ togglePanel($(this).val(), this.checked); });
  $(document).on('keyup change','#alc-form input', function(){ validateSubmit(); });
  $('#alc-privacy-link').on('click', function(){ $('#alc-privacy').prop('checked', true); validateSubmit(); });
  $('#alc-regolamento-link').on('click', function(){ $('#alc-regolamento').prop('checked', true); validateSubmit(); });
  $(document).on('click','.alc-verify-btn', function(){
    const cat=$(this).data('cat');
    const fd=new FormData();
    fd.append('action','alc_verify');
    fd.append('security',ALC_VARS.nonce);
    fd.append('categorie[]', cat);
    const f=$('input[name="manoscritto_'+cat+'"]')[0].files[0];
    if(!f){ alert('Seleziona un file per '+cat); return; }
    fd.append('manoscritto_'+cat, f);
    fetch(ALC_VARS.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
      if(!res.ok){ throw new Error((res.errors&&res.errors.join(' '))||'Verifica fallita'); }
      const d=res.per_category&&res.per_category[cat]?res.per_category[cat]:null;
      if(!d){ throw new Error('Nessun esito per '+cat); }
      let msg='Caratteri (spazi inclusi): '+d.char_count;
      if(cat==='poesia_inedita'){ msg+=' — Versi: '+d.versi; }
      $('#verify_'+cat).text(msg);
    }).catch(e=>alert(e.message));
  });
  $('#alc-form').on('submit', function(e){
    e.preventDefault();
    const fd=new FormData(this);
    fd.append('action','alc_submit');
    fd.append('security',ALC_VARS.nonce);
    fetch(ALC_VARS.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
      if(!res.ok){ throw new Error((res.errors&&res.errors.join('\n'))||'Errore di invio'); }
      const home = ALC_VARS.success_url || '/';
	$('#alc-result').html(
	  '<div class="alc-success">' +
		'<strong>Iscrizione effettuata con successo!</strong><br>' +
		'ID: <code id="alc-submission-id">'+res.submission_id+'</code>' +
		' <button type="button" class="alc-copy-id" aria-label="Copia ID iscrizione" ' +
		' data-clipboard-text="'+res.submission_id+'">Copia ID</button>' +
		'<p>Conserva il tuo ID iscrizione: ti servirà per eventuali modifiche, pagamenti o supporto.</p>' +
		'<div style="margin-top:10px;"><a class="alc-home-btn" href="'+home+'">Torna alla Home</a></div>' +
		'<div class="alc-copy-feedback" role="status" aria-live="polite" style="margin-top:6px;display:none;"></div>' +
	  '</div>'
	);
	$('#alc-form').hide();
    }).catch(e=>alert(e.message));
  });
	/* Copia negli appunti + feedback */
async function alcCopyToClipboard(text){
  // Tenta API moderna (richiede HTTPS)
  if (navigator.clipboard && window.isSecureContext){
    try { await navigator.clipboard.writeText(text); return true; } catch(e){}
  }
  // Fallback: seleziona e copia con execCommand
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch(e){ return false; }
}

// Event delegation: funziona anche se l’HTML è iniettato dopo
$(document).on('click', '.alc-copy-id', async function(e){
  e.preventDefault();
  const $btn = $(this);
  const $out = $('.alc-copy-feedback'); // è dentro lo stesso container
  const text = $btn.attr('data-clipboard-text') || $('#alc-submission-id').text() || '';
  const ok = await alcCopyToClipboard(text);
  if ($out.length){
    $out.show().text(ok ? 'ID copiato negli appunti.' : 'Copia non riuscita: seleziona e copia manualmente.');
  } else {
    alert(ok ? 'ID copiato negli appunti.' : 'Copia non riuscita: seleziona e copia manualmente.');
  }
});

  function paypalMountIfEnabled(){
    if (!ALC_PAYPAL) { console.debug('ALC_PAYPAL non definito'); return; }
    if (!ALC_PAYPAL.enabled) { console.debug('PayPal disabilitato'); return; }
    if (!window.paypal) { console.debug('PayPal SDK non caricato'); return; }
    const findContainer = () => document.getElementById('paypal-button-container');
    let mounted = false;
    const mount = () => {
      const el = findContainer();
      if (!el) { console.debug('Container PayPal non ancora nel DOM, ritento...'); return; }
      if (mounted || el.dataset.mounted === '1') { console.debug('PayPal già montato, skip.'); return; }
      $('#alc-paypal-wrap').show();
      el.dataset.mounted = '1';
      mounted = true;
      console.debug('Mount PayPal buttons...');
      try {
        paypal.Buttons({
          style:{layout:'vertical',shape:'rect'},
          onInit:function(d,a){
            if(!validateSubmit()) a.disable();
            $(document).on('change keyup','#alc-form input, #alc-form select', function(){
              if(validateSubmit()) a.enable(); else a.disable();
            });
          },
          createOrder:function(){
            const fd=new FormData();
            fd.append('action','alc_paypal_create_order');
            fd.append('security',ALC_VARS.nonce);
            $('input[name="categorie[]"]:checked').each(function(){ fd.append('categorie[]', $(this).val()); });
            return fetch(ALC_VARS.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
              if(!res.ok){ throw new Error(res.error||'createOrder failed'); }
              $('#submission_id').val(res.provisional_submission_id||'');
              $('#paypal_amount').val(res.amount);
              return res.orderID;
            });
          },
          onApprove:function(data){
            const fd=new FormData();
            fd.append('action','alc_paypal_capture_order');
            fd.append('security',ALC_VARS.nonce);
            fd.append('orderID', data.orderID);
            return fetch(ALC_VARS.ajax,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
              if(!res.ok){ throw new Error(res.error||'capture failed'); }
              $('#submission_id').val(res.orderID);
              $('#paypal_order_id').val(res.orderID);
              $('#paypal_capture_id').val(res.captureID||'');
              $('#paypal_payer_email').val(res.payer_email||'');
              $('#paypal_amount').val(res.amount||$('#paypal_amount').val());
              $('#alc-submit').prop('disabled', false);
              $('#alc-form').trigger('submit');
            }).catch(e=>alert('Pagamento non riuscito: '+e.message));
          },
          onError:function(err){ alert('Errore PayPal: '+(err&&err.message?err.message:'')); }
        }).render(findContainer());
      } catch (e) {
        console.error('PayPal render error:', e);
        mounted = false;
        const el2 = findContainer(); if (el2) el2.dataset.mounted = '0';
      }
    };
    mount();
    let tries=0; const iv=setInterval(function(){ if(mounted){clearInterval(iv);return;} tries++; if(tries>20){clearInterval(iv);return;} const el=findContainer(); if(el && el.dataset.mounted!=='1') mount(); },250);
    const mo = new MutationObserver(()=>{ const el=findContainer(); if(!mounted && el && el.dataset.mounted!=='1') mount(); });
    mo.observe(document.body,{childList:true,subtree:true});
  }
  computeTotal();
  if(window.paypal) paypalMountIfEnabled(); else { const iv=setInterval(function(){ if(window.paypal){ clearInterval(iv); paypalMountIfEnabled(); } }, 500); }
});
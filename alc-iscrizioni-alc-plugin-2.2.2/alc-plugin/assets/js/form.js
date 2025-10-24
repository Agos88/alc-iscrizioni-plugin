(function($){
  var CAT_LABELS = {'romanzo_inedito':'Romanzo inedito','romanzo_edito':'Romanzo edito','racconto_edito':'Racconto edito','poesia_inedita':'Componimento poetico inedito'};
  var TITLE_PLACEHOLDER = {'romanzo_inedito':'Titolo del romanzo inedito','romanzo_edito':'Titolo del romanzo edito','racconto_edito':'Titolo del racconto edito','poesia_inedita':'Titolo del componimento poetico'};
  var MAX_MB = (ALC_VARS && ALC_VARS.max_file_mb) ? ALC_VARS.max_file_mb : 10;
  function feeTotal(){ var fees = ALC_VARS.fees || {}; var total = 0; $('input[name="categorie[]"]:checked').each(function(){ var k=$(this).val(); if (fees[k]) total += parseFloat(fees[k]); }); $('#alc-total').text(total.toFixed(2)); $('#alc-currency').text(ALC_VARS.currency || 'EUR'); }
  function selectedCats(){ return $('input[name="categorie[]"]:checked').map(function(){ return $(this).val(); }).get(); }
  function panelId(cat){ return 'panel_'+cat; } function titleId(cat){ return 'titolo_'+cat; } function fileId(cat){ return 'manoscritto_'+cat; } function infoId(cat){ return 'info_'+fileId(cat); } function verifyBtnId(cat){ return 'verify_'+cat; } function toggleId(cat){ return 'toggle_'+cat; }
  function ensurePanel(cat){
    var pid = panelId(cat); if ($('#'+pid).length) return; var $p = $('<div/>',{id:pid,class:'alc-panel','data-cat':cat,'aria-expanded':'true'}); var nice = CAT_LABELS[cat]||cat;
    var $header = $('<div/>',{class:'alc-panel-h'}).append($('<button/>',{type:'button',id:toggleId(cat),class:'alc-toggle','aria-controls':pid,'aria-expanded':'true',text:nice}));
    var $body = $('<div/>',{class:'alc-panel-b'});
    var $title = $('<label/>',{for:titleId(cat),text:'Titolo ('+nice+') *'}).append($('<input/>',{type:'text',id:titleId(cat),name:titleId(cat),required:true,placeholder:TITLE_PLACEHOLDER[cat]||'Titolo'}));
    var $file = $('<label/>',{for:fileId(cat),text:'Manoscritto ('+nice+') (.txt, .docx, .doc) *'}).append($('<input/>',{type:'file',id:fileId(cat),name:fileId(cat),accept:'.txt,.doc,.docx',required:true}));
    var $note = $('<div/>',{class:'alc-note alc-muted'}).html('Dimensione massima consigliata: <strong>'+MAX_MB+' MB</strong>. Il conteggio dei caratteri considera tutti i caratteri compresi gli spazi. DOCX: estrazione da <code>word/document.xml</code>; TXT: lettura diretta; DOC: stima “best effort”.');
    var $verify = $('<button/>',{type:'button',id:verifyBtnId(cat),class:'alc-verify-btn',text:'Verifica '+nice}); var $info = $('<div/>',{id:infoId(cat),class:'alc-analysis'});
    $body.append($title).append($file).append($verify).append($note).append($info); $p.append($header).append($body); $('#alc-panels').append($p);
  }
  function removePanel(cat){ $('#'+panelId(cat)).remove(); }
  function syncPanels(){ var cats=selectedCats(); var existing=$('#alc-panels .alc-panel').map(function(){return $(this).data('cat');}).get(); cats.forEach(function(c){ if(existing.indexOf(c)===-1) ensurePanel(c); }); existing.forEach(function(c){ if(cats.indexOf(c)===-1) removePanel(c); }); }
  function allPanelsValid(){ var ok=true; selectedCats().forEach(function(cat){ var t=$('#'+titleId(cat)).val(); var f=$('#'+fileId(cat)).get(0); if(!t||!f||!f.files||!f.files.length) ok=false; }); return ok; }
  function updateSubmitState(){ var ok=document.getElementById('alc-form').checkValidity(); var catsOk=selectedCats().length>0 && allPanelsValid(); var checked=$('input[name="privacy"]').is(':checked') && $('input[name="regolamento"]').is(':checked'); $('#alc-submit').prop('disabled', !(ok && catsOk && checked)); }
  $(document).on('click','.alc-toggle',function(){ var cat=$(this).attr('id').replace('toggle_',''); var $panel=$('#'+panelId(cat)); var expanded=$panel.attr('aria-expanded')==='true'; $panel.attr('aria-expanded', expanded?'false':'true'); $(this).attr('aria-expanded', expanded?'false':'true'); });
  $(document).on('click','.alc-verify-btn',function(){ var cat=$(this).attr('id').replace('verify_',''); var fd=new FormData(); fd.append('action','alc_verify'); fd.append('security',ALC_VARS.nonce); fd.append('categorie[]',cat);
    var inp=document.getElementById(fileId(cat)); if(inp && inp.files && inp.files[0]){ fd.append(fileId(cat), inp.files[0]); }
    var $btn=$(this).prop('disabled',true).text('Verifica in corso...'); var $info=$('#'+infoId(cat)).empty();
    $.ajax({url:ALC_VARS.ajax, method:'POST', data:fd, processData:false, contentType:false}).done(function(resp){ var r=(resp.per_category&&resp.per_category[cat])?resp.per_category[cat]:null; if(!r){ $info.html('<div class="alc-error">Verifica non disponibile.</div>'); return; } var html='Caratteri '+(r.char_count||0); if(cat==='poesia_inedita'){ html+=' — Versi '+(r.versi||0); } $info.html(html); })
    .fail(function(xhr){ var err='Errore durante la verifica.'; try{ var j=JSON.parse(xhr.responseText); if(j.errors){ err=j.errors.join('<br>'); } }catch(e){} $info.html('<div class="alc-error">'+err+'</div>'); })
    .always(function(){ $btn.prop('disabled',false).text('Verifica '+(CAT_LABELS[cat]||cat)); });
  });
  $(document).on('change keyup', '#alc-form input, #alc-form select', function(e){
    if($(e.target).attr('name')==='categorie[]'){ syncPanels(); feeTotal(); updateSubmitState(); return; }
    if($(e.target).attr('type')==='file'){ var f=e.target.files && e.target.files[0]; if(f){ var mb=(f.size/1024/1024).toFixed(2); var cat=$(e.target).attr('id').replace('manoscritto_',''); $('#'+infoId(cat)).html('File: '+f.name+' — '+mb+' MB'); } }
    feeTotal(); updateSubmitState();
  });
  $(document).ready(function(){ $('#alc-currency').text(ALC_VARS.currency||'EUR'); syncPanels(); feeTotal(); updateSubmitState(); setTimeout(function(){ if($('#alc-panels').length){ syncPanels(); updateSubmitState(); } },300); });
  $('#alc-form').on('submit', function(e){
    e.preventDefault(); if(!allPanelsValid()){ updateSubmitState(); return; }
    var fd=new FormData(this); fd.append('action','alc_submit'); fd.append('security',ALC_VARS.nonce);
    $('#alc-submit').prop('disabled',true).text('Invio...');
    $.ajax({url:ALC_VARS.ajax, method:'POST', data:fd, processData:false, contentType:false}).done(function(resp){
      var r=resp.data||{}; var msg='<h3>Iscrizione inviata!</h3>'; msg+='<p>Totale quote: <strong>'+r.total.toFixed(2)+' '+r.currency+'</strong></p>';
      if(r.per_category){ msg+='<div><strong>Dettagli per categoria:</strong><ul>'; for(var cat in r.per_category){ var d=r.per_category[cat], nice=CAT_LABELS[cat]||cat;
        msg+='<li><em>'+nice+'</em>: '; if(d.titolo){ msg+='“'+d.titolo+'” — '; } msg+='caratteri '+(d.char_count||0); if(cat==='poesia_inedita'){ msg+=' — versi '+(d.versi||0); }
        if(typeof d.file_size!=='undefined'){ var mb=(d.file_size/1024/1024).toFixed(2); msg+=' — '+mb+' MB'; } if(d.file_url){ msg+=' — <a href="'+d.file_url+'" target="_blank" rel="noopener">file</a>'; }
        if(d.drive_link){ msg+=' — <a href="'+d.drive_link+'" target="_blank" rel="noopener">Drive</a>'; } msg+='</li>'; } msg+='</ul></div>'; }
      msg+='<p>Pagamento tramite bonifico bancario:<br><strong>IBAN</strong> IT90R0760104200001071784589 — Intestato a Ass. culturale Completa.Mente.<br/>Causale: “Iscrizione al Concorso Letterario Agitazioni Letterarie Castelluccesi”.</p>';
      $('#alc-result').html(msg); $('#alc-form')[0].reset(); $('#alc-panels').empty(); syncPanels(); feeTotal(); updateSubmitState();
    }).fail(function(xhr){ var err='Errore durante l\'invio.'; try{ var j=JSON.parse(xhr.responseText); if(j.errors){ err=j.errors.join('<br>'); } }catch(e){} $('#alc-result').html('<div class="alc-error">'+err+'</div>'); })
    .always(function(){ $('#alc-submit').prop('disabled',false).text('Invia candidatura'); });
  });
})(jQuery);
<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/layout.php';

$usuario = usuarioLogado();
$db      = getDB();

$stmt = $db->prepare("
    SELECT id, nome FROM " . tbl('albuns') . "
    WHERE usuario_id = :uid AND ativo = 1
    ORDER BY criado_em DESC
");
$stmt->execute([':uid' => $usuario['id']]);
$albuns = $stmt->fetchAll();

layoutInicio('Scanner — Câmera');
?>

<div class="scanner-wrap">
    <div>
        <a href="/albuns" class="btn-voltar">← Álbuns</a>
        <h1 class="page-title">Scanner de Figurinhas</h1>
    </div>

    <div class="scanner-config">
        <label for="scanner-album"><strong>Álbum:</strong></label>
        <select id="scanner-album">
            <?php foreach ($albuns as $album): ?>
                <option value="<?= $album['id'] ?>">
                    <?= htmlspecialchars($album['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="scanner-camera-wrap">
        <video id="camera" autoplay playsinline muted></video>
        <canvas id="canvas" style="display:none"></canvas>
        <div class="scanner-mira">
            <div class="mira-canto"></div>
            <p class="mira-dica">Alinhe o código aqui ↗</p>
        </div>
        <div id="scanner-status" class="scanner-status">Iniciando câmera...</div>
    </div>

    <div id="debug-recorte" style="display:none;margin-top:.5rem;">
        <p style="font-size:.8rem;color:#666;">Imagem enviada ao OCR:</p>
        <img id="img-recorte" style="width:100%;border:2px solid var(--amarelo);border-radius:4px;">
    </div>

    <div class="scanner-controles">
        <button id="btn-capturar" class="btn btn-primary" onclick="capturar()">📷 Capturar</button>
        <button id="btn-continuo" class="btn btn-secondary" onclick="toggleContinuo()">🔄 Modo contínuo: OFF</button>
    </div>

    <div id="resultado-wrap" class="resultado-wrap" style="display:none">
        <h3>Figurinhas detectadas</h3>
        <div id="lista-detectadas" class="lista-detectadas"></div>
        <div class="resultado-acoes">
            <button class="btn btn-primary" onclick="confirmarTodas()">✓ Confirmar todas</button>
            <button class="btn btn-sm" onclick="limparResultado()">✕ Limpar</button>
        </div>
    </div>

    <div id="log-wrap" class="log-wrap" style="display:none">
        <h3>Adicionadas nesta sessão</h3>
        <div id="log-itens"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
let stream=null,worker=null,modoContinuo=false,timerContinuo=null,processando=false,detectadas={};
const video=document.getElementById('camera'),canvas=document.getElementById('canvas'),status=document.getElementById('scanner-status'),ctx=canvas.getContext('2d');
const siglas=['PNN','CC','FWC','ALG','ARG','AUS','AUT','BEL','BIH','BRA','CAN','CIV','COD','COL','CPV','CRO','CUW','CZE','ECU','EGY','ENG','ESP','FRA','GER','GHA','HAI','IRN','IRQ','JOR','JPN','KOR','KSA','MAR','MEX','NED','NOR','NZL','PAN','PAR','POR','QAT','RSA','SCO','SEN','SUI','SWE','TUN','TUR','URU','USA','UZB'];

async function iniciarCamera(){try{stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:1280,height:720}});video.srcObject=stream;status.textContent='Câmera pronta.';status.className='scanner-status ok';}catch(e){status.textContent='Erro: '+e.message;status.className='scanner-status erro';}}
async function iniciarTesseract(){status.textContent='Carregando OCR...';worker=await Tesseract.createWorker('eng',1,{logger:m=>{if(m.status==='recognizing text')status.textContent='Lendo... '+Math.round(m.progress*100)+'%';}});await worker.setParameters({tessedit_char_whitelist:'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 ',tessedit_pageseg_mode:'7',textord_disable_font_properties:'1'});await iniciarCamera();}

async function capturar(){
    if(processando||!worker)return;processando=true;status.textContent='Processando...';
    const w=video.videoWidth,h=video.videoHeight;canvas.width=w;canvas.height=h;ctx.drawImage(video,0,0);
    const recorteX=Math.floor(w*0.44),recorteY=Math.floor(h*0.40),recorteW=Math.floor(w*0.18),recorteH=Math.floor(h*0.16);
    const canvasRecorte=document.createElement('canvas');canvasRecorte.width=recorteW*2;canvasRecorte.height=recorteH*2;
    const ctxR=canvasRecorte.getContext('2d');ctxR.filter='contrast(1.8) grayscale(1) brightness(1.1)';
    ctxR.drawImage(canvas,recorteX,recorteY,recorteW,recorteH,0,0,canvasRecorte.width,canvasRecorte.height);
    const imageData=canvasRecorte.toDataURL('image/png');
    document.getElementById('debug-recorte').style.display='block';document.getElementById('img-recorte').src=imageData;
    const{data:{text}}=await worker.recognize(imageData);
    let textoOriginal=text.toUpperCase().replace(/[^A-Z0-9]/g,' ');
    let textoParaSiglas=textoOriginal.replace(/0/g,'O');
    const siglasPattern=siglas.join('|');
    const regex=new RegExp(`(${siglasPattern})\\s*([A-Z0-9]{1,3})`,'g');
    const encontrados=[];let m;
    while((m=regex.exec(textoParaSiglas))!==null){
        let indiceMatch=m.index;
        let pedacoOriginal=textoOriginal.substring(indiceMatch,regex.lastIndex);
        let numMatch=pedacoOriginal.match(/\d+/);
        if(numMatch){encontrados.push(m[1]+numMatch[0].padStart(2,'0'));}
    }
    status.textContent='Lido: '+(textoOriginal.trim()||'vazio');
    if(encontrados.length>0){const únicos=[...new Set(encontrados)];mostrarFeedbackRapido('Detectado: '+únicos.join(', '));await validarCodigos(únicos);}
    processando=false;
}

async function validarCodigos(codigos){
    const albumId=document.getElementById('scanner-album').value;
    try{
        const resp=await fetch('/api/scanner',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'codigos='+encodeURIComponent(JSON.stringify(codigos))+'&album_id='+albumId});
        const data=await resp.json();
        if(data.validos&&data.validos.length>0){mostrarDetectadas(data.validos);status.textContent='Figurinhas na lista. Confirme para salvar.';status.className='scanner-status ok';if(navigator.vibrate)navigator.vibrate(50);}
    }catch(err){status.textContent='Erro ao validar.';}finally{processando=false;}
}

function mostrarDetectadas(validos){
    const wrap=document.getElementById('resultado-wrap'),lista=document.getElementById('lista-detectadas');
    wrap.style.display='';
    validos.forEach(fig=>{if(!detectadas[fig.codigo])detectadas[fig.codigo]={...fig,qtd:1};else detectadas[fig.codigo].qtd++;});
    lista.innerHTML='';
    Object.values(detectadas).forEach(fig=>{
        const div=document.createElement('div');div.className='detectada-item';
        div.innerHTML=`<span class="det-codigo">${fig.codigo}</span><span class="det-nome">${fig.nome}</span>
            <div class="det-controles"><button onclick="ajustarDetectada('${fig.codigo}',-1)">−</button>
            <span id="det-qtd-${fig.codigo}">${fig.qtd}</span>
            <button onclick="ajustarDetectada('${fig.codigo}',1)">+</button>
            <button class="det-remover" onclick="removerDetectada('${fig.codigo}')">✕</button></div>`;
        lista.appendChild(div);
    });
}

function ajustarDetectada(codigo,delta){if(!detectadas[codigo])return;detectadas[codigo].qtd=Math.max(1,detectadas[codigo].qtd+delta);document.getElementById('det-qtd-'+codigo).textContent=detectadas[codigo].qtd;}
function removerDetectada(codigo){delete detectadas[codigo];mostrarDetectadas([]);if(Object.keys(detectadas).length===0)limparResultado();}

async function confirmarTodas(){
    const albumId=document.getElementById('scanner-album').value,itens=Object.values(detectadas);
    if(itens.length===0)return;status.textContent='Gravando lote...';
    try{
        const resp=await fetch('/api/scanner',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'confirmar=1&itens='+encodeURIComponent(JSON.stringify(itens))+'&album_id='+albumId});
        const data=await resp.json();
        if(data.sucesso){
            const log=document.getElementById('log-itens'),logWrap=document.getElementById('log-wrap');logWrap.style.display='';
            itens.forEach(fig=>{const p=document.createElement('p');p.textContent='✓ '+fig.codigo+' — '+fig.nome+' (+'+fig.qtd+')';p.style.color='green';p.style.margin='2px 0';log.prepend(p);});
            limparResultado();status.textContent=itens.length+' figurinha(s) adicionada(s)!';status.className='scanner-status ok';
            if(navigator.vibrate)navigator.vibrate([100,50,100]);
        }else throw new Error(data.erro||'Erro ao gravar');
    }catch(err){status.textContent='Erro ao confirmar.';status.className='scanner-status erro';}
}

function limparResultado(){detectadas={};document.getElementById('resultado-wrap').style.display='none';document.getElementById('lista-detectadas').innerHTML='';}
function toggleContinuo(){
    modoContinuo=!modoContinuo;const btn=document.getElementById('btn-continuo');
    if(modoContinuo){btn.textContent='🔄 Modo contínuo: ON';btn.style.background='#28a745';btn.style.color='#fff';executarCicloContinuo();}
    else{btn.textContent='🔄 Modo contínuo: OFF';btn.style.background='';btn.style.color='';clearTimeout(timerContinuo);}
}
async function executarCicloContinuo(){if(!modoContinuo)return;if(!processando){await capturar();status.textContent='Aguardando 3s...';timerContinuo=setTimeout(executarCicloContinuo,3000);}else timerContinuo=setTimeout(executarCicloContinuo,1000);}
function mostrarFeedbackRapido(msg){status.textContent='✅ '+msg;status.classList.add('ok');if(navigator.vibrate)navigator.vibrate(100);setTimeout(()=>{if(!modoContinuo){status.textContent='Pronto para próxima.';status.classList.remove('ok');}},2000);}
iniciarTesseract();
</script>
<?php layoutFim(); ?>

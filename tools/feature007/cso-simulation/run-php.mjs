#!/usr/bin/env node
/** Real, pinned official PHPWASM CLI simulation; not native PHP or live WordPress. */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads';

const OFFICIAL_COMMIT = '4d3932270ebfdc7654d9b1cb3954841229d1fd2d';
const ASSETS = {
  '7.4': { version: '7.4.33', js: ['packages/php-wasm/node-builds/7-4/asyncify/php_7_4.js','676be27cfcdb5428b794b83e23acb0efeb1e0ea3'], wasm: ['packages/php-wasm/node-builds/7-4/asyncify/7_4_33/php_7_4.wasm','cca9119d84f2a76e430e27a1ae39bc456999998b'] },
  '8.3': { version: '8.3.33', js: ['packages/php-wasm/node-builds/8-3/asyncify/php_8_3.js','a0eb7e7c3392e9ee623ef55302789cc9e377fe6e'], wasm: ['packages/php-wasm/node-builds/8-3/asyncify/8_3_33/php_8_3.wasm','d62885c83faeca8a4862d2c5be1c290fba43986c'] }
};
const MAX_FILES=50000, MAX_BYTES=256*1024*1024, MAX_OUTPUT=8*1024*1024;
const EXCLUDED=new Set(['.git','.ssh','.aws','.cache','node_modules','__pycache__']);
const sha=data=>crypto.createHash('sha256').update(data).digest('hex');
const gitBlob=data=>crypto.createHash('sha1').update(Buffer.from(`blob ${data.length}\0`)).update(data).digest('hex');
const inside=(root,p)=>p===root||p.startsWith(root+path.sep);
const ownFile=fileURLToPath(import.meta.url);

function snapshot(root, retain=true) {
  const rows=[], files=[]; let bytes=0, skipped=0;
  function walk(dir, rel='') {
    for(const name of fs.readdirSync(dir).sort()) {
      if(EXCLUDED.has(name)||name==='.env'||name.startsWith('.env.')) { skipped++; continue; }
      const full=path.join(dir,name), relative=rel?`${rel}/${name}`:name;
      const info=fs.lstatSync(full);
      if(info.isSymbolicLink()) { skipped++; continue; }
      if(info.isDirectory()) { if(!inside(root,fs.realpathSync(full)))throw Error('source directory escaped root');walk(full,relative);continue; }
      if(!info.isFile()) { skipped++;continue; }
      if(rows.length>=MAX_FILES||bytes+info.size>MAX_BYTES)throw Error('source snapshot exceeds file/byte limit');
      const fd=fs.openSync(full,fs.constants.O_RDONLY|fs.constants.O_NOFOLLOW);
      let data;
      try {
        if(!inside(root,fs.realpathSync(`/proc/self/fd/${fd}`)))throw Error('source file escaped root');
        if(!fs.fstatSync(fd).isFile())throw Error('source file changed type');
        data=fs.readFileSync(fd);
      } finally { fs.closeSync(fd); }
      bytes+=data.length;if(bytes>MAX_BYTES)throw Error('source snapshot exceeds byte limit');
      rows.push([relative,data.length,sha(data)]);
      if(retain)files.push({relative,data});
    }
  }
  walk(root);
  return { files, hash:sha(JSON.stringify(rows)), count:rows.length, bytes, skipped, rows };
}

function parse(argv) {
  const opts={php:'',repo:'',file:'',lint:false,report:'',timeout:30000,runtime:path.resolve(path.dirname(ownFile),'../php-wasm-runtime-4d393227'),args:[]};
  for(let i=0;i<argv.length;i++) {
    const arg=argv[i];
    if(arg==='--') {opts.args=argv.slice(i+1);break;}
    if(arg==='--lint') {opts.lint=true;continue;}
    const map={'--php':'php','--repo':'repo','--file':'file','--report':'report','--runtime':'runtime','--timeout-ms':'timeout'};
    if(!map[arg]||i+1>=argv.length)throw Error(`unknown or incomplete option ${arg}`);
    opts[map[arg]]=argv[++i];
  }
  if(!ASSETS[opts.php]||!opts.repo||!opts.file)throw Error('usage: run-php.mjs --php 7.4|8.3 --repo DIRECTORY --file RELATIVE.php [--lint] [--report OUTSIDE.json] [--runtime OFFICIAL_CHECKOUT] [--timeout-ms 30000] [-- SCRIPT_ARGS]');
  opts.timeout=Number(opts.timeout);
  if(!Number.isInteger(opts.timeout)||opts.timeout<1000||opts.timeout>120000)throw Error('timeout must be 1000..120000ms');
  if(path.isAbsolute(opts.file)||opts.file.includes('\\')||opts.file.split('/').some(s=>!s||s==='.'||s==='..'))throw Error('file must be a safe relative path');
  opts.repo=fs.realpathSync(opts.repo);opts.runtime=fs.realpathSync(opts.runtime);
  if(!fs.statSync(opts.repo).isDirectory())throw Error('repo must be a directory');
  if(opts.report) {opts.report=path.resolve(opts.report);if(inside(opts.repo,opts.report))throw Error('report must be outside the source snapshot');}
  return opts;
}

async function supervisor() {
  const opts=parse(process.argv.slice(2));
  const before=snapshot(opts.repo);
  const target=before.files.find(f=>f.relative===opts.file);
  if(!target)throw Error('fixture absent from regular-file snapshot');
  const assets={};
  for(const kind of ['js','wasm']) {
    const [relative,expected]=ASSETS[opts.php][kind];
    const data=fs.readFileSync(path.join(opts.runtime,relative));
    if(gitBlob(data)!==expected)throw Error(`official ${kind} Git blob differs from pin`);
    assets[kind]={relative,git_blob:expected,sha256:sha(data),bytes:data.length,data};
  }
  // An unchanged .mjs copy avoids Node's implicit .js module-type warning.
  const cache=path.join(path.dirname(ownFile),'loader-cache');fs.mkdirSync(cache,{recursive:true});
  const jsFile=path.join(cache,`php-${opts.php}-${assets.js.sha256}.mjs`);
  if(!fs.existsSync(jsFile)||sha(fs.readFileSync(jsFile))!==assets.js.sha256)fs.writeFileSync(jsFile,assets.js.data,{mode:0o600});
  const worker=new Worker(new URL(import.meta.url),{
    env:{},stdout:true,stderr:true,
    workerData:{files:before.files,php:opts.php,file:opts.file,lint:opts.lint,args:opts.args,loader:pathToFileURL(jsFile).href,wasm:assets.wasm.data},
    resourceLimits:{maxOldGenerationSizeMb:768}
  });
  const started=Date.now(), outHash=crypto.createHash('sha256'),errHash=crypto.createHash('sha256');
  let stdoutBytes=0,stderrBytes=0,runCode=null,termination='',diagnostic='',complete=false;
  const receive=(stream,raw)=>{
    const data=Buffer.from(raw);
    if(stream==='stdout'){stdoutBytes+=data.length;outHash.update(data);process.stdout.write(data);}
    else {stderrBytes+=data.length;errHash.update(data);process.stderr.write(data);}
    if(stdoutBytes+stderrBytes>MAX_OUTPUT&&!termination){termination='output_limit';worker.terminate();}
  };
  worker.stdout.on('data',data=>receive('stdout',data));
  worker.stderr.on('data',data=>receive('stderr',data));
  const timer=setTimeout(()=>{if(!complete&&!termination){termination='wall_timeout';worker.terminate();}},opts.timeout);
  worker.on('message',msg=>{
    if(msg.type==='output')receive(msg.stream,msg.data);
    if(msg.type==='done'){runCode=msg.code;diagnostic=msg.diagnostic||'';}
  });
  await new Promise(resolve=>{
    worker.on('error',error=>{diagnostic=String(error.message);if(!termination)termination='runtime_error';});
    worker.on('exit',code=>{complete=true;clearTimeout(timer);if(runCode===null&&!termination){termination='runtime_error';diagnostic=diagnostic||`worker exited ${code} without PHP status`;}resolve();});
  });
  let after=null,immutable=false;
  try{after=snapshot(opts.repo,false);immutable=after.hash===before.hash&&after.bytes===before.bytes&&after.count===before.count;}
  catch(error){diagnostic=diagnostic||String(error.message);}
  let code=termination==='wall_timeout'?124:termination==='output_limit'?125:termination?70:runCode;
  if(!immutable&&code===0)code=71;
  const report={
    kind:'official-php-wasm-simulation',native_ci:false,live_wordpress:false,
    official_repository:'https://github.com/WordPress/wordpress-playground',official_commit:OFFICIAL_COMMIT,
    php_requested:opts.php,php_expected:ASSETS[opts.php].version,node_version:process.version,
    runner_sha256:sha(fs.readFileSync(ownFile)),fixture:opts.file,fixture_sha256:sha(target.data),fixture_args:opts.args,lint:opts.lint,
    assets:Object.fromEntries(Object.entries(assets).map(([kind,asset])=>[kind,{...asset,data:undefined}])),
    source_snapshot:{root:opts.repo,sha256:before.hash,files:before.count,bytes:before.bytes,skipped:before.skipped,hash_format:'sha256(JSON.stringify(sorted [relative-path,byte-length,sha256] rows))'},
    source_after:after?{sha256:after.hash,files:after.count,bytes:after.bytes}:null,source_immutability_verified:immutable,
    stdout:{bytes:stdoutBytes,sha256:outHash.digest('hex')},stderr:{bytes:stderrBytes,sha256:errHash.digest('hex')},
    php_exit_code:runCode,exit_code:code,termination:termination||null,diagnostic:diagnostic||null,elapsed_ms:Date.now()-started,
    limits:{snapshot_files:MAX_FILES,snapshot_bytes:MAX_BYTES,output_bytes:MAX_OUTPUT,wall_ms:opts.timeout,php_memory:'256M',worker_environment:'empty'},
    limitations:['WASM rather than native PHP','Private MEMFS; no host mounts or process provider','Network access disabled','No live DB/WordPress/browser/Redis acceptance','Private MEMFS flock does not prove native multiprocess exclusion','Sodium/pcntl not available in these pinned builds']
  };
  if(opts.report){fs.mkdirSync(path.dirname(opts.report),{recursive:true});fs.writeFileSync(opts.report,JSON.stringify(report,null,2)+'\n');}
  if(diagnostic)process.stderr.write(`PHPWASM runner: ${diagnostic}\n`);
  process.exitCode=code;
}

async function execute() {
  const data=workerData, loader=await import(data.loader);
  let runtime;
  const byteStreams={stdout:[],stderr:[]};
  function send(stream,chunk) {
    // PHPWASM supplies borrowed HEAP subarrays. Clone synchronously BEFORE queueing!
    const copy=Buffer.from(chunk);
    if(copy.length)parentPort.postMessage({type:'output',stream,data:copy});
  }
  function flush(stream) {if(byteStreams[stream].length){send(stream,Buffer.from(byteStreams[stream]));byteStreams[stream]=[];}}
  function byte(stream,value){if(value===null||value===undefined){flush(stream);return;}byteStreams[stream].push(value&255);if(byteStreams[stream].length>=4096)flush(stream);}
  let initialized,initializeFailed;
  const ready=new Promise((resolve,reject)=>{initialized=resolve;initializeFailed=reject;});
  runtime=loader.init('NODE',{
    ENV:{HOME:undefined,USER:undefined,LOGNAME:undefined,LANG:'C.UTF-8',PWD:'/repo',OPENSSL_CONF:'/tmp/openssl.cnf'},wasmBinary:Buffer.from(data.wasm),locateFile:name=>name,noInitialRun:true,
    stdin:()=>null,stdout:value=>byte('stdout',value),stderr:value=>byte('stderr',value),
    onStdout:chunk=>{flush('stdout');send('stdout',chunk);},onStderr:chunk=>{flush('stderr');send('stderr',chunk);},onHeaders:()=>{},
    onRuntimeInitialized:initialized,onAbort:reason=>initializeFailed(Error(String(reason))),
    websocket:{url:()=>{throw Error('network disabled for PHPWASM simulation');},serverDecorator:()=>class{constructor(){throw Error('network server disabled');}}}
  });
  await ready;
  runtime.FS.mkdirTree('/repo');
  runtime.FS.writeFile('/tmp/openssl.cnf',Buffer.from('[openssl_init]\n'));
  for(const file of data.files){const dest='/repo/'+file.relative;runtime.FS.mkdirTree(path.posix.dirname(dest));runtime.FS.writeFile(dest,Buffer.from(file.data));}
  runtime.FS.chdir('/repo');
  const disabled='allow_url_fopen=Off';
  const args=['php','-n','-d','display_errors=stderr','-d','log_errors=Off','-d','error_reporting=32767','-d','memory_limit=256M','-d',disabled,'-d','allow_url_include=Off','-d','disable_functions=dl,curl_exec,curl_multi_exec,mail,socket_create,socket_create_listen,socket_create_pair,stream_socket_server',...(data.lint?['-l']:[]),'/repo/'+data.file,...data.args];
  for(const arg of args)runtime.ccall('wasm_add_cli_arg',null,['string'],[arg]);
  let code=0,diagnostic='';
  try {const result=await runtime.ccall('run_cli','number',[],[],{async:true});if(Number.isInteger(result))code=result;}
  catch(error) {if(error&&Number.isInteger(error.status))code=error.status;else{code=70;diagnostic=String(error?.message||error);}}
  flush('stdout');flush('stderr');parentPort.postMessage({type:'done',code,diagnostic});
  process.exitCode=0;parentPort.close();
}

if(isMainThread)supervisor().catch(error=>{process.stderr.write(`PHPWASM runner: ${error.message}\n`);process.exitCode=70;});
else execute().catch(error=>{parentPort.postMessage({type:'done',code:70,diagnostic:String(error.message)});parentPort.close();});

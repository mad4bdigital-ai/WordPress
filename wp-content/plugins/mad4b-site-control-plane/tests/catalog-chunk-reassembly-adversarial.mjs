import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
import {createAbilityCatalogClient} from '../client/ability-catalog-client.mjs';

const encoder = new TextEncoder();
const digest = async bytes => [...new Uint8Array(await webcrypto.subtle.digest('SHA-256', bytes))]
  .map(n => n.toString(16).padStart(2, '0')).join('');
const hex = c => c.repeat(64);
const schemaJson = JSON.stringify({type:'object',description:'x'.repeat(2300),properties:{id:{type:'integer'}}});
const schemaBytes = encoder.encode(schemaJson);
const schemaSha = await digest(schemaBytes);
const scope = hex('a');
const snapshot = hex('b');
const inputSha = hex('c');
const classificationSha = hex('d');
const chunkSize = 1024;
const chunkCount = Math.ceil(schemaBytes.length / chunkSize);

function fixture({wireGeneration = 'generation-a', fault = ''} = {}) {
  const calls = [];
  const callDiscover = async input => {
    if (input.transport_action === 'capabilities') {
      return {
        contract:'mad4b.ability-catalog-transport.v2',
        authority_scope_sha256:scope,
        transports:['mcp_base64'],
        wire_generation:wireGeneration,
      };
    }
    if (input.gateway_action === 'prepare') {
      return {
        contract:'mad4b.unified-capability-gateway.v1',
        transfer_policy:{recommended_parallel_schema_fetches:1},
        abilities:[{
          ability_name:'fixture/read',
          authority_scope_sha256:scope,
          snapshot,
          classification:'read',
          execution_eligible:true,
          execution:{state:'governed_dispatch'},
          input_schema_sha256:inputSha,
          classification_sha256:classificationSha,
          preparation_receipt:'fixture-receipt',
          source:{sha256:schemaSha,bytes:schemaBytes.length},
        }],
      };
    }
    if (input.transport_action === 'chunk') {
      const i = input.chunk_index;
      calls.push(i);
      const start = i * chunkSize;
      const expected = Math.min(chunkSize, schemaBytes.length - start);
      let bytes = schemaBytes.slice(start, start + expected);
      let index = i;
      let generation = wireGeneration;
      let compression = 'none';
      if (fault === 'mixed_generation' && i === 1) generation = 'generation-foreign';
      if (fault === 'reordered' && i === 1) index = 0;
      if (fault === 'duplicate' && i === 1) bytes = schemaBytes.slice(0, Math.min(chunkSize, expected));
      if (fault === 'missing' && i === 1) bytes = new Uint8Array();
      if (fault === 'compressed' && i === 1) compression = 'gzip';
      const checksum = await digest(bytes);
      let data = Buffer.from(bytes).toString('base64');
      if (fault === 'oversized_base64' && i === 1) data = 'A'.repeat(4 * Math.ceil(expected / 3) + 1);
      return {
        contract:'mad4b.ability-catalog-transport.v2',
        wire_generation:generation,
        snapshot,
        schema_sha256:schemaSha,
        schema_bytes:schemaBytes.length,
        schema_format:'source',
        compression,
        encoding:'base64',
        chunk_index:index,
        chunk_bytes:chunkSize,
        chunk_offset:i * chunkSize,
        chunk_payload_bytes:expected,
        chunk_count:chunkCount,
        chunk_sha256:checksum,
        data,
      };
    }
    throw new Error('unexpected fixture request');
  };
  const client = createAbilityCatalogClient({
    callDiscover,
    cryptoImpl:webcrypto,
    minChunkBytes:chunkSize,
    maxChunkBytes:chunkSize,
    maxParallelSchemaFetches:1,
    maxSchemaBytes:1024 * 1024,
  });
  return {client,calls};
}

async function catalog(client) {
  const prepared = await client.prepare(['fixture/read']);
  return prepared.catalogs.get('fixture/read');
}

{
  const {client,calls} = fixture();
  const target = await catalog(client);
  const read = await client.readSchema(target,'fixture/read');
  assert.equal(read.sha256,schemaSha);
  assert.equal(read.json,schemaJson);
  assert.deepEqual(calls,[0,1,2]);
  assert.equal(read.state.chunks.size,chunkCount);
  for (const row of read.state.chunks.values()) assert.equal(row.wireGeneration,'generation-a');
}

for (const [fault,pattern] of [
  ['mixed_generation',/chunk identity/i],
  ['reordered',/chunk identity/i],
  ['duplicate',/chunk integrity|aggregate integrity/i],
  ['missing',/chunk integrity/i],
  ['compressed',/chunk identity/i],
  ['oversized_base64',/memory budget/i],
]) {
  const {client} = fixture({fault});
  const target = await catalog(client);
  await assert.rejects(client.readSchema(target,'fixture/read'),pattern,`${fault} chunk fault was accepted`);
}

// Resume state from generation A is never reusable under generation B, even if the schema digest is unchanged.
{
  const first = fixture({wireGeneration:'generation-a'});
  const firstCatalog = await catalog(first.client);
  const completed = await first.client.readSchema(firstCatalog,'fixture/read');
  const second = fixture({wireGeneration:'generation-b'});
  const secondCatalog = await catalog(second.client);
  await assert.rejects(
    second.client.readSchema(secondCatalog,'fixture/read',{state:completed.state}),
    /resume authority or schema mismatch/i,
    'mixed-generation resume cache was accepted',
  );
}

console.log('mad4b.catalog-chunk-reassembly.adversarial.v1: PASS');

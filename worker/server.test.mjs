import test from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
process.env.THREADS_WORKER_SECRET='a'.repeat(40);
process.env.NODE_ENV='test';
const {validUuid,signatureValid}=await import('./server.mjs');
test('only UUID profile names',()=>{
  assert.equal(validUuid('c303cf39-75a7-46b6-99b9-9d04f00a75f3'),true);
  for (const item of ['../other','foo','c303cf39-75a7-46b6-99b9-9d04f00a75f3/../../']) assert.equal(validUuid(item),false);
});
test('worker requires exact HMAC',()=>{
  const key='a'.repeat(40), body=Buffer.from('{"uuid":"example"}');
  const sign=crypto.createHmac('sha256',key).update(body).digest('hex');
  assert.equal(signatureValid(body,sign,key),true);
  assert.equal(signatureValid(Buffer.from('{}'),sign,key),false);
  assert.equal(signatureValid(body,'broken',key),false);
});

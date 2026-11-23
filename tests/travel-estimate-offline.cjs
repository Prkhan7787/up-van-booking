'use strict';
const assert = require('node:assert/strict');
const {travelNumbers} = require('../public/assets/travel-estimate.js');
const result = travelNumbers(120000, 125000, 7200000, 10, 40);
assert.deepEqual(result, {outboundKm:120,totalKm:245,durationMinutes:120,litres:24.5,cost:980});
const missing = travelNumbers(120000, 125000, 7200000, 0, null);
assert.equal(missing.litres, null); assert.equal(missing.cost, null);
assert.throws(() => travelNumbers(-1,100,100,10,40), /ROUTE_MEASURE_INVALID/);
console.log('PASS: browser-side route arithmetic and absent fuel data');

const {readOsrmRoute} = require('../public/assets/travel-estimate.js');
const routeFixture = {code:'Ok', waypoints:[{distance:2},{distance:3}], routes:[{distance:120, duration:60, geometry:{type:'LineString',coordinates:[[99,19],[98,18]]}}]};
assert.equal(readOsrmRoute(routeFixture).distance,120);
assert.throws(()=>readOsrmRoute({code:'NoSegment'}),/OSRM_NoSegment/);
assert.throws(()=>readOsrmRoute({...routeFixture, routes:[{...routeFixture.routes[0],duration:-1}]}),/OSRM_INVALID_RESPONSE/);
assert.throws(()=>readOsrmRoute({...routeFixture,waypoints:[{distance:2000},{distance:0}]}),/OSRM_INVALID_RESPONSE/);
console.log('PASS: OSRM JSON, NoSegment, invalid measures and distant road snaps');

const {fuelPriceFor} = require('../public/assets/travel-estimate.js');
const feedFixture={status:'PASS', prices:{'ไฮดีเซล S':40.79,'แก๊สโซฮอล์ 95 S EVO':39.34}};
assert.equal(fuelPriceFor(feedFixture,'ไฮดีเซล S'),40.79);
assert.equal(fuelPriceFor(feedFixture,'แก๊สโซฮอล์ 95 S EVO'),39.34);
assert.equal(fuelPriceFor(feedFixture,''),null);
assert.equal(fuelPriceFor(feedFixture,'ไฮดีเซล S '),null);
assert.equal(fuelPriceFor({status:'UNAVAILABLE'},'ไฮดีเซล S'),null);
assert.equal(fuelPriceFor({status:'PASS',prices:{'ไฮดีเซล S':0}},'ไฮดีเซล S'),null);
console.log('PASS: exact fuel match, switching fuel, absent type, API unavailable and invalid price');

const {referenceFuelEstimate} = require('../public/assets/travel-estimate.js');
const reference={usable:true, fuel_type:'แก๊สโซฮอล์ 95 S EVO',unit:'กม./ลิตร',samples:[7.95,9.76,9.19]};
const estimate=referenceFuelEstimate(120000,125000,39.34,reference,reference.fuel_type);
assert.ok(Math.abs(estimate.mean-26.9/3)<1e-12);
assert.equal(estimate.costMin,245/9.76*39.34); assert.equal(estimate.costMax,245/7.95*39.34);
assert.ok(estimate.costMin<estimate.cost && estimate.cost<estimate.costMax);
for(const values of [[null,125000,39.34,reference,reference.fuel_type],[120000,125000,null,reference,reference.fuel_type],[120000,125000,39.34,null,reference.fuel_type],[120000,125000,39.34,reference,'ไฮดีเซล S'],[120000,125000,39.34,{...reference,usable:false},reference.fuel_type]])assert.equal(referenceFuelEstimate(...values),null);
assert.notEqual(referenceFuelEstimate(1000,2000,39.34,reference,reference.fuel_type).cost,estimate.cost);
console.log('PASS: sample mean, inverse cost bounds, changed distance and missing/unsupported data');

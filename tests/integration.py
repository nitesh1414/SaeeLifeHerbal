#!/usr/bin/env python3
"""Destructive integration smoke test. Run only against a fresh disposable database.
Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD; create the company admin first.
"""
import os, json, urllib.request, urllib.error, http.cookiejar, datetime, uuid
from zoneinfo import ZoneInfo
BASE=os.getenv('BASE_URL','http://127.0.0.1:8001').rstrip('/')+'/api/index.php?action='
EMAIL=os.environ['ADMIN_EMAIL']; PASSWORD=os.environ['ADMIN_PASSWORD']
class Client:
    def __init__(self):
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=self.call('bootstrap')['csrf']
    def call(self,action,data=None,expect=200):
        headers={'X-CSRF-Token':getattr(self,'csrf','')}; payload=None
        if data is not None:payload=json.dumps(data).encode();headers['Content-Type']='application/json'
        req=urllib.request.Request(BASE+action,data=payload,headers=headers)
        try:r=self.opener.open(req);status=r.status;body=r.read()
        except urllib.error.HTTPError as e:status=e.code;body=e.read()
        if status!=expect:raise AssertionError(f'{action}: expected {expect}, got {status}: {body.decode()}')
        result=json.loads(body);self.csrf=result.get('csrf',getattr(self,'csrf','')) if isinstance(result,dict) else self.csrf;return result
anon=Client();anon.call('admin_members',expect=401);anon.call('save_page',expect=405)
admin=Client();admin.call('admin_login',{'email':EMAIL,'password':PASSWORD})
admin.call('save_settings',{'home_title':'Managed home'})
settings=admin.call('admin_settings');assert settings['home_title']=='Managed home' and settings['company_sponsor_code']=='COMPANY'
admin.call('save_rule',{'kind':'milestone','threshold':1,'amount':10})
admin.call('save_rule',{'kind':'royalty','threshold':1,'amount':5})
p=admin.call('save_product',{'name':'Integration product','category':'Test','description':'Test','price':100,'direct_commission':30,'grand_commission':20,'content':'<p onclick="bad()">Safe<script>bad()</script></p>'})
products=admin.call('bootstrap')['products'];assert next(x for x in products if x['id']==p['id'])['content']=='<p>Safe</p>'
admin.call('save_page',{'slug':'integration-test','title':'Test page','content':'<p onclick="bad()">Safe<script>bad()</script></p>','published':'on'})
assert next(x for x in admin.call('bootstrap')['pages'] if x['slug']=='integration-test')['content']=='<p>Safe</p>'
def register(name,sponsor=''):
    c=Client();r=c.call('register',{'name':name,'phone':'1234567890','email':name.lower()+'@example.test','password':'Testing123!','sponsor_code':sponsor});return c,r['user']['member_code']
a,ac=register('Alice');b,bc=register('Bob',ac);c,cc=register('Carol',bc)
c.call('place_order',{'shipping_address':'Test','contact_phone':'123','items':[{'product_id':1,'quantity':1}]},400)
assert float(a.call('member_dashboard')['user']['wallet'])==10
receipt='test-'+uuid.uuid4().hex
purchase={'member_code':cc,'product_id':p['id'],'receipt_reference':receipt,'quantity':2}
admin.call('record_purchase',purchase);admin.call('record_purchase',purchase,400)
assert float(a.call('member_dashboard')['user']['wallet'])==50
assert float(b.call('member_dashboard')['user']['wallet'])==70
period=datetime.datetime.now(ZoneInfo(os.getenv('BUSINESS_TIMEZONE','Asia/Kolkata'))).strftime('%Y-%m');r=admin.call('run_royalty',{'period':period});assert r['awarded']==2
assert admin.call('run_royalty',{'period':period})['awarded']==0
assert float(a.call('member_dashboard')['user']['wallet'])==55
kyc={'address':'Test address','kyc_number':'TEST123','bank_name':'Alice','bank_account':'12345678','bank_ifsc':'ABCD0123456'}
a.call('update_kyc',kyc);a.call('request_payout',{'amount':25},400)
member=next(x for x in admin.call('admin_members') if x['member_code']==ac)
admin.call('verify_kyc',{'id':member['id'],'status':'verified','fingerprint':member['fingerprint']})
a.call('request_payout',{'amount':25});assert float(a.call('member_dashboard')['user']['wallet'])==30
request=next(x for x in admin.call('admin_payouts') if x['member_code']==ac)
admin.call('payout_status',{'id':request['id'],'status':'approved'})
admin.call('payout_status',{'id':request['id'],'status':'rejected'})
admin.call('payout_status',{'id':request['id'],'status':'rejected'},400)
assert float(a.call('member_dashboard')['user']['wallet'])==55
with admin.opener.open(BASE+'payout_csv') as response: csv=response.read().decode()
assert '12345678' in csv and 'ABCD0123456' in csv
member=next(x for x in admin.call('admin_members') if x['member_code']==ac)
a.call('update_kyc',{**kyc,'bank_account':'87654321'})
admin.call('verify_kyc',{'id':member['id'],'status':'verified','fingerprint':member['fingerprint']},400)
order=c.call('place_order',{'shipping_address':'Test delivery','contact_phone':'1234567890','items':[{'product_id':p['id'],'quantity':1}]})
assert any(x['id']==order['id'] for x in admin.call('admin_orders'))
admin.call('order_status',{'id':order['id'],'status':'confirmed','receipt_reference':receipt},400)
admin.call('order_status',{'id':order['id'],'status':'confirmed','receipt_reference':'order-'+uuid.uuid4().hex})
admin.call('order_status',{'id':order['id'],'status':'confirmed','receipt_reference':'duplicate'},400)
assert float(a.call('member_dashboard')['user']['wallet'])==75
assert float(b.call('member_dashboard')['user']['wallet'])==105
# Refunds only record an external transfer; ledger reversal is atomic and idempotent.
admin.call('refund_order',{'id':order['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Test return'})
admin.call('refund_order',{'id':order['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Duplicate'},400)
assert float(a.call('member_dashboard')['user']['wallet'])==55
assert float(b.call('member_dashboard')['user']['wallet'])==75
standalone=next(x for x in admin.call('admin_purchases') if x['receipt_reference']==receipt)
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Test return'})
assert float(a.call('member_dashboard')['user']['wallet'])==15
assert float(b.call('member_dashboard')['user']['wallet'])==15
assert any(x['action']=='refund_order' for x in admin.call('admin_audit'))
# Admin-only MIME-checked image upload.
boundary='----saee-test-boundary';image=open('assets/icon-512.png','rb').read()
body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="icon.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image+f'\r\n--{boundary}--\r\n'.encode())
req=urllib.request.Request(BASE+'upload',data=body,headers={'X-CSRF-Token':admin.csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
with admin.opener.open(req) as response: uploaded=json.load(response)
assert uploaded['type']=='image' and uploaded['url'].startswith('uploads/')
os.remove(uploaded['url'])
print('PASS: upload, authorization, order checkout and confirmation, CMS sanitization, registration, milestone, 2-level purchase, duplicate receipt, royalty, KYC and payout transitions')

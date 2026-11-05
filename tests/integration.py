#!/usr/bin/env python3
"""Destructive integration smoke test. Run only against a fresh disposable database.
Set BASE_URL, ADMIN_EMAIL and ADMIN_PASSWORD; create the company admin first.
"""
import os, json, urllib.request, urllib.error, http.cookiejar, datetime, uuid, re, base64, hmac, hashlib, struct, time
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
admin.call('save_settings',{'home_title':'Managed home','feature_image_url':'assets/products.jpg','hero_shop_button':'Shop now'})
settings=admin.call('admin_settings');assert settings['home_title']=='Managed home' and settings['company_sponsor_code']=='COMPANY'
admin.call('save_rule',{'kind':'milestone','threshold':1,'amount':10})
admin.call('save_rule',{'kind':'royalty','threshold':1,'amount':5})
p=admin.call('save_product',{'name':'Integration product','category':'Test','description':'Test','price':100,'direct_commission':30,'grand_commission':20,'content':'<p onclick="bad()">Safe<script>bad()</script></p>'})
products=admin.call('bootstrap')['products'];assert next(x for x in products if x['id']==p['id'])['content']=='<p>Safe</p>'
admin.call('save_page',{'slug':'integration-test','title':'Test page','content':'<p onclick="bad()">Safe<script>bad()</script></p>','published':'on'})
assert next(x for x in admin.call('bootstrap')['pages'] if x['slug']=='integration-test')['content']=='<p>Safe</p>'
def register(name,sponsor=''):
    c=Client();r=c.call('register',{'name':name,'phone':'1234567890','email':name.lower()+'@example.test','password':'Testing123!','sponsor_code':sponsor});return c,r['user']['member_code']
# Staff authorization is enforced server-side, independently of navigation.
def staff(name,role):
    created=admin.call('create_staff',{'name':name,'email':name.lower()+'@example.test','password':'StaffPassword123!','role':role})
    client=Client();client.call('admin_login',{'email':name.lower()+'@example.test','password':'StaffPassword123!'})
    return client,created['id']
content,content_id=staff('Editor','content');ops,ops_id=staff('Operations','operations');finance,finance_id=staff('Finance','finance')
content.call('admin_products');content.call('admin_members',expect=403);content.call('admin_staff',expect=403)
content.call('save_settings',{'home_title':'Editorial change','nav_home':'Start'});content.call('save_settings',{'company_sponsor_code':'COMPANY'},403);content.call('save_settings',{'feature_image_url':'assets/products.jpg'},403)
content.call('save_product',{'id':p['id'],'name':'Integration product','category':'Test','description':'Test','price':100,'direct_commission':31,'grand_commission':20,'content':'<p>Safe</p>'},403)
content.call('save_product',{'id':p['id'],'name':'Integration product','category':'Test','description':'Test','price':100,'direct_commission':30,'grand_commission':20,'content':'<p>Safe</p>'})
ops.call('admin_members');ops.call('admin_payouts',expect=403);ops.call('record_purchase',{},403);ops.call('order_status',{},403)
finance.call('admin_payouts');finance.call('admin_staff',expect=403);finance.call('verify_kyc',{},403)
owner=next(x for x in admin.call('admin_staff') if x['admin_role']=='owner')
admin.call('update_staff',{'id':owner['id'],'role':'finance','active':'0'},400)
admin.call('update_staff',{'id':content_id,'role':'finance','active':'1'});content.call('admin_overview',expect=401)
content.call('admin_login',{'email':'editor@example.test','password':'StaffPassword123!'});content.call('admin_payouts');content.call('admin_products',expect=403)
admin.call('update_staff',{'id':finance_id,'role':'finance','active':'0'});finance.call('admin_overview',expect=401)
finance.call('admin_login',{'email':'finance@example.test','password':'StaffPassword123!'},401)
assert any(x['action']=='create_staff' for x in admin.call('admin_audit'))

a,ac=register('Alice');b,bc=register('Bob',ac);c,cc=register('Carol',bc)
# Optional local test mail sink: configure PHP sendmail_path to append to this file.
mail_capture=os.environ.get('TEST_MAIL_CAPTURE')
if mail_capture:
    open(mail_capture,'w').close()
    a.call('request_verification',{});a.call('request_verification',{})
    with open(mail_capture) as stream: message=stream.read()
    tokens=re.findall(r'#verify-email/([a-f0-9]{64})',message);assert len(tokens)==2 and tokens[0]!=tokens[1], message
    assert not a.call('member_dashboard')['user']['email_verified_at']
    anonymous=Client();anonymous.call('verify_email',{'token':tokens[0]},400)
    anonymous.call('verify_email',{'token':tokens[1]})
    anonymous.call('verify_email',{'token':tokens[1]},400)
    assert a.call('member_dashboard')['user']['email_verified_at']
    assert not b.call('member_dashboard')['user']['email_verified_at']
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
# Refunds record external transfers and reverse the corresponding credits atomically.
admin.call('refund_order',{'id':order['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Test return','customer_amount':'100'})
admin.call('refund_order',{'id':order['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Duplicate','customer_amount':'100'},400)
assert float(a.call('member_dashboard')['user']['wallet'])==55
assert float(b.call('member_dashboard')['user']['wallet'])==75
standalone=next(x for x in admin.call('admin_purchases') if x['receipt_reference']==receipt)
r1='refund-'+uuid.uuid4().hex
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':r1,'reason':'Invalid quantity','quantity':'1.5','customer_amount':'10'},400)
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':r1,'reason':'One unit','quantity':1,'customer_amount':'101'},400)
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':r1,'reason':'One unit','quantity':1,'customer_amount':'100'})
assert float(a.call('member_dashboard')['user']['wallet'])==35
assert float(b.call('member_dashboard')['user']['wallet'])==45
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':r1,'reason':'Duplicate reference','quantity':1,'customer_amount':'100'},400)
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Remaining unit','quantity':1,'customer_amount':'100'})
assert float(a.call('member_dashboard')['user']['wallet'])==15
assert float(b.call('member_dashboard')['user']['wallet'])==15
admin.call('refund_purchase',{'id':standalone['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Excess','quantity':1,'customer_amount':'100'},400)
# A partial order-line return updates the order; refunding the remaining units completes it.
p2=admin.call('save_product',{'name':'Second product','category':'Test','description':'Test','price':90,'direct_commission':7,'grand_commission':4,'content':''})
order2=c.call('place_order',{'shipping_address':'Test delivery','contact_phone':'1234567890','items':[{'product_id':p['id'],'quantity':2},{'product_id':p2['id'],'quantity':3}]})
admin.call('order_status',{'id':order2['id'],'status':'confirmed','receipt_reference':'order-'+uuid.uuid4().hex})
line=next(x for x in admin.call('admin_purchases') if x['order_id']==order2['id'] and x['product_id']==p['id'])
admin.call('refund_purchase',{'id':line['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Partial return','quantity':1,'customer_amount':'75'})
assert next(x for x in admin.call('admin_orders') if x['id']==order2['id'])['status']=='partially_refunded'
assert float(a.call('member_dashboard')['user']['wallet'])==47 and float(b.call('member_dashboard')['user']['wallet'])==66
admin.call('refund_order',{'id':order2['id'],'refund_reference':'refund-'+uuid.uuid4().hex,'reason':'Return remainder','customer_amount':'370'})
assert next(x for x in admin.call('admin_orders') if x['id']==order2['id'])['status']=='refunded'
assert float(a.call('member_dashboard')['user']['wallet'])==15 and float(b.call('member_dashboard')['user']['wallet'])==15
assert len(admin.call('admin_refunds'))==5
assert any(x['action']=='refund_order' for x in admin.call('admin_audit'))
# Admin-only MIME-checked image upload.
boundary='----saee-test-boundary';image=open('assets/icon-512.png','rb').read()
body=(f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="icon.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+image+f'\r\n--{boundary}--\r\n'.encode())
req=urllib.request.Request(BASE+'upload',data=body,headers={'X-CSRF-Token':admin.csrf,'Content-Type':'multipart/form-data; boundary='+boundary})
with admin.opener.open(req) as response: uploaded=json.load(response)
assert uploaded['type']=='image' and uploaded['url'].startswith('uploads/')
os.remove(uploaded['url'])
if mail_capture:
    # Reset links delivered to a local test sink are single-use and revoke all existing sessions.
    open(mail_capture,'w').close()
    recovery=Client();recovery.call('forgot_password',{'email':'alice@example.test'})
    with open(mail_capture) as stream: message=stream.read()
    reset_token=re.search(r'#reset/([a-f0-9]{64})',message);assert reset_token, message
    recovery.call('reset_password',{'token':reset_token.group(1),'password':'Newpassword123!'})
    recovery.call('reset_password',{'token':reset_token.group(1),'password':'Newpassword123!'},400)
    a.call('member_dashboard',expect=401)
    again=Client();again.call('login',{'email':'alice@example.test','password':'Testing123!'},401)
    again.call('login',{'email':'alice@example.test','password':'Newpassword123!'})
    open(mail_capture,'w').close();recovery.call('forgot_password',{'email':'editor@example.test'})
    with open(mail_capture) as stream: message=stream.read()
    reset_token=re.search(r'#reset/([a-f0-9]{64})',message);assert reset_token
    recovery.call('reset_password',{'token':reset_token.group(1),'password':'short123'},400)
    recovery.call('reset_password',{'token':reset_token.group(1),'password':'NewStaffPassword123!'})
    content.call('admin_overview',expect=401)
# Owner opt-in TOTP, one-time recovery codes, replay prevention, and session revocation.
assert not admin.call('admin_security')['enabled']
other_admin=Client();other_admin.call('admin_login',{'email':EMAIL,'password':PASSWORD})
setup=admin.call('mfa_begin',{'password':PASSWORD});secret=base64.b32decode(setup['secret']+'='*((8-len(setup['secret'])%8)%8))
counter=int(time.time())//30;digest=hmac.new(secret,struct.pack('>Q',counter),hashlib.sha1).digest();offset=digest[-1]&15;otp=f'{(int.from_bytes(digest[offset:offset+4],"big")&0x7fffffff)%1000000:06d}'
enabled=admin.call('mfa_enable',{'code':otp});assert len(enabled['recovery_codes'])==10
assert admin.call('admin_security')['enabled']
other_admin.call('admin_overview',expect=401)
assert 'mfa_secret_cipher' not in admin.call('bootstrap')['user']
admin.call('logout',{})
owner_password=PASSWORD
if mail_capture:
    open(mail_capture,'w').close();owner_reset=Client();owner_reset.call('forgot_password',{'email':EMAIL})
    with open(mail_capture) as stream: message=stream.read()
    token=re.search(r'#reset/([a-f0-9]{64})',message);assert token
    owner_password='NewOwnerPassword123!';owner_reset.call('reset_password',{'token':token.group(1),'password':owner_password})
challenge=Client();challenge_result=challenge.call('admin_login',{'email':EMAIL,'password':owner_password});assert challenge_result['mfa_required'] and 'user' not in challenge_result
challenge.call('admin_overview',expect=401)
challenge.call('mfa_finish_login',{'code':otp},401)
challenge.call('mfa_finish_login',{'code':enabled['recovery_codes'][0]});assert challenge.call('admin_security')['enabled']
challenge.call('logout',{});challenge2=Client();assert challenge2.call('admin_login',{'email':EMAIL,'password':owner_password})['mfa_required']
challenge2.call('mfa_finish_login',{'code':enabled['recovery_codes'][0]},401)
challenge2.call('mfa_finish_login',{'code':enabled['recovery_codes'][1]})
challenge2.call('mfa_disable',{'password':'incorrect','code':enabled['recovery_codes'][2]},401)
challenge2.call('mfa_disable',{'password':owner_password,'code':enabled['recovery_codes'][2]})
assert not challenge2.call('admin_security')['enabled'];challenge2.call('logout',{})
last=Client();assert 'user' in last.call('admin_login',{'email':EMAIL,'password':owner_password})
print('PASS: MFA, role authorization, email/recovery (if mail sink enabled), upload, order checkout and confirmation, CMS sanitization, registration, milestone, 2-level purchase, duplicate receipt, royalty, KYC, partial refunds and payout transitions')

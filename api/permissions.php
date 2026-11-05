<?php
// All admin endpoints are default-deny for restricted staff; only owners may manage staff or settings.
function allowedAdminAction(array $u,string $action): bool {
    if(empty($u['is_admin'])||empty($u['is_active']))return false;
    $role=$u['admin_role']??'';
    if($role==='owner')return true;
    $content=['admin_overview','admin_products','admin_pages','admin_slides','admin_marketing','admin_socials','admin_media','upload','save_product','delete_product','save_page','delete_page','save_slide','delete_slide','save_social','delete_social','save_media','delete_media','save_settings'];
    $operations=['admin_overview','admin_members','admin_orders','verify_kyc'];
    $finance=['admin_overview','admin_members','admin_orders','admin_purchases','admin_refunds','admin_rules','admin_payouts','payout_csv','record_purchase','record_payout','order_status','refund_order','refund_purchase','payout_status','save_rule','delete_rule','run_royalty'];
    $roles=['content'=>$content,'operations'=>$operations,'finance'=>$finance];
    return in_array($action,$roles[$role]??[],true);
}
function marketingSetting(string $key): bool {
    return in_array($key,['home_eyebrow','home_title','home_description','feature_eyebrow','feature_title','feature_description','products_eyebrow','products_title','products_description','community_eyebrow','community_title','community_description','announcement_text','announcement_link_text','trust_1_title','trust_1_text','trust_2_title','trust_2_text','trust_3_title','trust_3_text','trust_4_title','trust_4_text','category_1_title','category_1_text','category_2_title','category_2_text','category_3_title','category_3_text','category_4_title','category_4_text','feature_check_1','feature_check_2','feature_check_3','feature_check_4','feature_image_title','feature_image_text','footer_description','footer_contact_text','hero_kicker','hero_shop_button','hero_community_button','feature_button','products_button','community_button','nav_home','nav_about','nav_products','nav_community','nav_contact','footer_explore','footer_links','footer_contact_heading'],true);
}

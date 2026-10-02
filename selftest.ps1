# 全流程自测脚本 v2（对线上 kf.ylcz.cc 新系统；幂等可重跑）
$ErrorActionPreference = 'Continue'
$base = 'https://kf.ylcz.cc'
$pass = 0; $fail = 0
function Step($name, $cond, $detail) {
  if ($cond) { Write-Host "[PASS] $name" -ForegroundColor Green; $script:pass++ }
  else { Write-Host "[FAIL] $name  ==> $detail" -ForegroundColor Red; $script:fail++ }
}
function ReadErr($ex) {
  $err = $ex.ErrorDetails.Message
  if (-not $err -and $ex.Exception.Response) {
    try { $sr = New-Object System.IO.StreamReader($ex.Exception.Response.GetResponseStream()); $err = $sr.ReadToEnd() } catch { $err = $ex.Exception.Message }
  }
  if (-not $err) { $err = $ex.Exception.Message }
  try { return ($err | ConvertFrom-Json) } catch { return @{error = "$err"} }
}
function ApiPost($url, $obj, $token) {
  $h = @{}; if ($token) { $h['Authorization'] = "Bearer $token" }
  try { return Invoke-RestMethod -Uri ($base + $url) -Method Post -ContentType 'application/json; charset=utf-8' -Headers $h -Body ($obj | ConvertTo-Json -Depth 8) } catch { return ReadErr $_ }
}
function ApiGet($url, $token) {
  $h = @{}; if ($token) { $h['Authorization'] = "Bearer $token" }
  try { return Invoke-RestMethod -Uri ($base + $url) -Method Get -Headers $h } catch { return ReadErr $_ }
}
function ApiPut($url, $obj, $token) {
  $h = @{}; if ($token) { $h['Authorization'] = "Bearer $token" }
  try { return Invoke-RestMethod -Uri ($base + $url) -Method Put -ContentType 'application/json; charset=utf-8' -Headers $h -Body ($obj | ConvertTo-Json -Depth 8) } catch { return ReadErr $_ }
}
function ApiDel($url, $token) {
  $h = @{}; if ($token) { $h['Authorization'] = "Bearer $token" }
  try { return Invoke-RestMethod -Uri ($base + $url) -Method Delete -Headers $h } catch { return ReadErr $_ }
}

Write-Host "`n========== 0. 清理旧测试数据 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/auth/login' @{account='admin'; password='123456'}
$admin = $r.token
Step '管理员登录' ($admin) ($r | ConvertTo-Json -Compress)
# 快照-恢复模式：只清理本脚本专属测试数据，不动用户真实配置
$savedRoot = (ApiGet '/api/admin/root-domain' $admin).root_domain
$savedDefs = ApiGet '/api/admin/defaults' $admin
$r = ApiGet '/api/admin/tenants' $admin
foreach ($t in @($r)) { if ($t.kami -like 'TESTKAMI*' -or @('租户B','租户C','调试租户') -contains $t.nickname) { ApiDel ("/api/admin/tenants/" + $t.id) $admin | Out-Null; Write-Host "  已删除遗留测试租户 $($t.kami)（$($t.nickname)）" } }
$r = ApiGet '/api/admin/domain-pool' $admin
foreach ($d in @($r)) { if ($d.domain -like '*demo-pool*') { ApiDel ("/api/admin/domain-pool/" + $d.id) $admin | Out-Null; Write-Host "  已删除旧测试域名 $($d.domain)" } }
# 收尾恢复函数（脚本结束时调用）
function RestoreEnv {
  try {
    ApiPost '/api/admin/root-domain' @{root_domain=$script:savedRoot} $script:admin | Out-Null
    ApiPost '/api/admin/defaults' @{default_quota_web=$script:savedDefs.default_quota_web; default_quota_app=$script:savedDefs.default_quota_app; default_max_agents=$script:savedDefs.default_max_agents} $script:admin | Out-Null
    $r = ApiGet '/api/admin/domain-pool' $script:admin
    foreach ($d in @($r)) { if ($d.domain -like '*demo-pool*') { ApiDel ("/api/admin/domain-pool/" + $d.id) $script:admin | Out-Null } }
    $r = ApiGet '/api/admin/tenants' $script:admin
    foreach ($t in @($r)) { if ($t.kami -like 'TESTKAMI*' -or @('租户B','租户C','调试租户') -contains $t.nickname) { ApiDel ("/api/admin/tenants/" + $t.id) $script:admin | Out-Null } }
    Write-Host "  环境已恢复（root_domain=$script:savedRoot）"
  } catch { Write-Host "  恢复环境失败: $($_.Exception.Message)" }
}

Write-Host "`n========== 1. 管理员身份 ==========" -ForegroundColor Cyan
$r = ApiGet '/api/auth/me' $admin
Step '管理员 me' ($r.role -eq 'admin' -and $r.default_password -eq $true) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 2. 管理员配置 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/admin/root-domain' @{root_domain='kf-abc.example.com'} $admin
Step '设置主域名' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/admin/domain-pool' @{domain='*.demo-pool.com'; wildcard=1} $admin
Step '域名池添加泛解析域名' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/admin/defaults' @{default_quota_web=200; default_quota_app=100; default_max_agents=3} $admin
Step '默认额度保存' ($r.ok) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 3. 创建租户 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/admin/tenants' @{kami='TESTKAMI1'; password='tenant123'; nickname='租户A'; expires_at=(Get-Date).AddDays(30).ToString('yyyy-MM-dd HH:mm:ss'); quota_web=2; quota_app=1; max_agents=2; remark='测试租户'} $admin
Step '创建租户A（自定义卡密）' ($r.ok -and $r.plain.kami -eq 'TESTKAMI1') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/admin/tenants' @{nickname='租户B'; password='tenant234'} $admin
Step '创建租户B（自动卡密）' ($r.ok -and $r.plain.kami.Length -eq 10) ($r | ConvertTo-Json -Compress)
$kamiB = $r.plain.kami
$r = ApiPost '/api/admin/tenants' @{kami='TESTKAMI1'; password='x123456'} $admin
Step '重复卡密被拒' ($r.error) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 4. 租户A登录 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1'; password='tenant123'}
Step '租户A登录' ($r.token -and $r.redirect -eq '/app.html') ($r | ConvertTo-Json -Compress)
$tenantA = $r.token
$r = ApiGet '/api/auth/me' $tenantA
Step '租户A me（额度正确）' ($r.role -eq 'tenant' -and $r.kami -eq 'TESTKAMI1' -and $r.quota_web -eq 2 -and $r.quota_app -eq 1 -and $r.max_agents -eq 2) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1'; password='wrongpass'}
Step '错误密码被拒(401)' ($r.error -match '密码') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='NOSUCHKAMI'; password='x'}
Step '不存在卡密被拒(401)' ($r.error -match '不存在') ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 5. 租户A配置 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/welcome-messages' @{content='你好 {name}，欢迎咨询！'; trig='once'; enabled=1; sort=0} $tenantA
Step '新增欢迎语' ($r.id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auto-reply' @{keyword='价格'; reply='您好，价格请添加微信详询~'; enabled=1} $tenantA
Step '新增自动回复' ($r.id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/quick-msgs' @{title='开场白'; content='您好，请问有什么可以帮您？'; group_name='通用'; sort=0} $tenantA
Step '新增快捷回复' ($r.id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/pre-inquiry' @{label='您的称呼'; type='text'; required=1; placeholder='怎么称呼您'} $tenantA
Step '新增询前表单项' ($r.id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/ip-blacklist' @{ip='1.2.3.4'; reason='测试黑名单'} $tenantA
Step '新增黑名单（CRUD）' ($r.id) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 6. 访客进入（直链） ==========" -ForegroundColor Cyan
$r = ApiGet '/api/visitor/config?t=TESTKAMI1'
Step '访客 config（可用+品牌）' ($r.available -eq $true -and $r.brand.company_name) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/pre-inquiry?t=TESTKAMI1'
Step '访客询前表单（名称识别）' (@($r).Count -ge 1 -and @($r)[0].key -eq 'name') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/config'
Step '无参数访客 config 被拒(404)' ($r.error -match '无效') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI1'; name='小明'; phone='13800138000'; question='你们价格怎么样？'; from_url='https://mp.weixin.qq.com/s/xxx'}
Step '访客 init' ($r.session_id) ($r | ConvertTo-Json -Compress)
$sidA = $r.session_id; $vtA = $r.visitor_token
$r = ApiPost '/api/visitor/welcome' @{session_id=$sidA; name='小明'}
Step '欢迎语下发（含{name}替换）' ($r.sent -and $r.count -ge 1) ($r | ConvertTo-Json -Compress)
$r = ApiGet "/api/visitor/messages?session_id=$sidA"
Step '访客历史（首问+欢迎语>=2）' (@($r.messages).Count -ge 2) ($r | ConvertTo-Json -Compress)
# 黑名单：用访客真实出口 IP 测试拦截
$r = ApiGet '/api/sessions' $tenantA
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
$realIp = $r[0].ip
$r = ApiPost '/api/ip-blacklist' @{ip=$realIp; reason='自测拦截'} $tenantA
$blId = $r.id
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI1'; visitor_token='vt_blacklist_probe'}
Step '黑名单真实IP拦截 init' ($r.error -match '受限') ($r | ConvertTo-Json -Compress)
$r = ApiDel "/api/ip-blacklist/$blId" $tenantA
Step '移除黑名单' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $tenantA
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '拦截未产生多余会话' (@($r).Count -eq 1) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 7. 访客发消息 → 自动回复 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/visitor/send' @{session_id=$sidA; text='请问价格是多少'}
Step '访客发言' ($r.ok -and $r.message.id) ($r | ConvertTo-Json -Compress)
Step '关键词自动回复触发' ($r.auto_reply -and $r.auto_reply.text -match '微信') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/send' @{session_id=$sidA; text=''}
Step '空消息被拒' ($r.error) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 8. 租户A工作台 ==========" -ForegroundColor Cyan
$r = ApiGet '/api/sessions' $tenantA
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '会话列表（1条，含未读）' (@($r).Count -eq 1 -and $r[0].name -eq '小明' -and $r[0].unread -ge 1) ($r | ConvertTo-Json -Compress)
$r = ApiPost "/api/sessions/$sidA/messages" @{text='您好，价格私聊详询哈'} $tenantA
Step '客服回复' ($r.message.id) ($r | ConvertTo-Json -Compress)
# 轮询增量：先拿最大消息 id，访客再发一条，poll 应只返回新消息
$r = ApiGet "/api/sessions/$sidA/messages" $tenantA
$maxId = $r.messages[-1].id
ApiPost '/api/visitor/send' @{session_id=$sidA; text='好嘞，加你了'} | Out-Null
$r = ApiGet "/api/poll?role=agent&session=$sidA&sinceMsg=$maxId" $tenantA
Step '客服轮询增量消息（拿到访客新消息）' ($r.messages.Count -eq 1 -and $r.messages[0].text -eq '好嘞，加你了') ($r | ConvertTo-Json -Compress)
$r = ApiPost "/api/sessions/999999/messages" @{text='越权测试'} $tenantA
Step '不存在会话被拒(404)' ($r.error) ($r | ConvertTo-Json -Compress)
$r = ApiPut "/api/sessions/$sidA/contacts/wechat" @{value='wxid_test123'} $tenantA
Step '保存访客微信' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiGet "/api/sessions/$sidA/profile" $tenantA
Step '访客资料' ($r.session.name -eq '小明' -and $r.visit_count -ge 1) ($r | ConvertTo-Json -Compress)
$r = ApiPost "/api/sessions/$sidA/read" @{} $tenantA
Step '标记已读' ($r.ok) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 9. 租户隔离 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/auth/login' @{account=$kamiB; password='tenant234'}
$tenantB = $r.token
Step '租户B登录' ($tenantB) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $tenantB
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '租户B会话列表为空' (@($r).Count -eq 0) ($r | ConvertTo-Json -Compress)
$r = ApiGet "/api/sessions/$sidA/messages" $tenantB
Step '租户B读租户A会话被拒' ($r.error) ($r | ConvertTo-Json -Compress)
$r = ApiPost "/api/sessions/$sidA/close" $tenantB
Step '租户B关租户A会话被拒' ($r.error) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/quick-msgs' $tenantB
Step '租户B快捷回复为空' (@($r).Count -eq 0) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/auto-reply' $tenantB
Step '租户B自动回复为空' (@($r).Count -eq 0) ($r | ConvertTo-Json -Compress)
# 租户B 自己的访客
$r = ApiPost '/api/visitor/init' @{t=$kamiB; name='B的访客'}
Step '租户B访客进入' ($r.session_id) ($r | ConvertTo-Json -Compress)
$sidB = $r.session_id
$r = ApiGet '/api/sessions' $tenantB
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '租户B只见自己的会话' (@($r).Count -eq 1 -and $r[0].name -eq 'B的访客') ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 10. 子客服 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/tenant/agents' @{username='kefu01'; nickname='客服小美'; password='agent123'} $tenantA
Step '创建子客服（返回登录账号）' ($r.ok -and $r.login_account -eq 'TESTKAMI1/kefu01') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/tenant/agents' @{username='kefu02'; nickname='客服二号'} $tenantA
Step '创建第二个子客服' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/tenant/agents' @{username='kefu03'; nickname='超额'} $tenantA
Step '超出 max_agents=2 被拒' ($r.error -match '上限') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/tenant/agents' @{username='kefu01'; nickname='重复'} $tenantA
Step '重复用户名被拒' ($r.error -match '已存在') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1/kefu01'; password='agent123'}
Step '子客服登录' ($r.token -and $r.user.role -eq 'agent') ($r | ConvertTo-Json -Compress)
$agent1 = $r.token
$r = ApiGet '/api/sessions' $agent1
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '子客服可见待接入会话' (@($r).Count -ge 1) ($r | ConvertTo-Json -Compress)
$r = ApiPost "/api/sessions/$sidA/messages" @{text='我是子客服小美~'} $agent1
Step '子客服回复' ($r.message.id) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $agent1
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
$mine = @($r) | Where-Object { $_.id -eq $sidA }
Step '回复后自动接手' ($mine.agent_id -gt 0) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/tenant/settings' @{} $agent1
Step '子客服访问租户管理被拒' ($r.error -match '无权限') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/quick-msgs' $agent1
Step '子客服可读共享话术' (@($r).Count -ge 1) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $tenantB
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '子客服看不到租户B会话（隔离）' ((@($r) | Where-Object { $_.name -eq '小明' }) -eq $null) ($r | ConvertTo-Json -Compress)
# 转接
$r = ApiPost "/api/sessions/$sidA/transfer" @{to_agent_id=0} $tenantA
Step '租户转接回待接入池' ($r.ok) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 11. 访客轮询 + 会话恢复 ==========" -ForegroundColor Cyan
$r = ApiGet "/api/poll?session=$sidA&sinceMsg=0"
Step '访客轮询消息完整（>5）' (@($r.messages).Count -ge 6) (@($r.messages).Count)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI1'; visitor_token=$vtA}
Step '同 token 恢复会话' ($r.reused -eq $true -and $r.session_id -eq $sidA) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI1'; visitor_token='vt_others'}
Step '不同 token 新建会话' ($r.session_id -and $r.session_id -ne $sidA) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 12. 额度 ==========" -ForegroundColor Cyan
# quota_web=2：已有 小明 + 新token访客 = 2 个 web 会话，第 3 个应被拒
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI1'; name='超额访客'}
Step '超额 web 访客被拒' ($r.error -match '名额') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/config?t=TESTKAMI1'
Step 'config 提示额度状态' ($r.quota_full) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 13. 客服码 + 品牌设置 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/tenant/service-code' @{} $tenantA
Step '一键换码生成链接' ($r.code.link -match '^https://[a-z0-9.-]+/visitor\.html\?code=') ($r | ConvertTo-Json -Compress)
$codeVal = $r.code.code
$r = ApiGet "/api/visitor/config?code=$codeVal"
Step '客服码 config 可用' ($r.available) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{code=$codeVal; name='扫码访客'}
Step '客服码访客 init（app 渠道）' ($r.session_id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{code='BOGUSCODE1'; name='假码'}
Step '无效客服码被拒' ($r.error) ($r | ConvertTo-Json -Compress)
$r = ApiPut '/api/tenant/settings' @{brand_company='金牌顾问小美'; brand_title='加我领资料'; brand_color='#ff6600'} $tenantA
Step '品牌设置保存' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/config?t=TESTKAMI1'
Step '访客端读到新品牌' ($r.brand.company_name -eq '金牌顾问小美' -and $r.brand.theme_color -eq '#ff6600') ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 14. 群发 + 统计 + 导出 ==========" -ForegroundColor Cyan
$r = ApiPost '/api/broadcast/send' @{text='今晚8点直播，不见不散'; title='直播通知'} $tenantA
Step '群发（>=2 会话）' ($r.ok -and $r.count -ge 2) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/stats/dashboard' $tenantA
Step '租户统计（额度已用=2/1）' ($r.total_sessions -ge 3 -and $r.quota.web_used -eq 2 -and $r.quota.app_used -eq 1) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/stats/dashboard' $agent1
Step '子客服统计接口可用' ($r.today_sessions -ne $null) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/tenant/links' $tenantA
Step '入口链接汇总（专属+直链+客服码）' ($r.direct_link -match 'visitor.html\?t=TESTKAMI1' -and @($r.codes).Count -ge 1) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 15. 过期 / 停用 ==========" -ForegroundColor Cyan
$r = ApiGet '/api/admin/tenants' $admin
$tid1 = (@($r) | Where-Object { $_.kami -eq 'TESTKAMI1' }).id
$r = ApiPut "/api/admin/tenants/$tid1" @{expires_at='2020-01-01 00:00:00'} $admin
Step '管理员改有效期为过去' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1'; password='tenant123'}
Step '过期租户登录被拒' ($r.error -match '到期') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/auth/me' $tenantA
Step '过期租户旧 token 立即失效' ($r.error -match '到期') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/config?t=TESTKAMI1'
Step '过期租户访客端不可用' ($r.available -eq $false) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1/kefu01'; password='agent123'}
Step '过期租户的子客服登录被拒' ($r.error -match '到期') ($r | ConvertTo-Json -Compress)
$r = ApiPut "/api/admin/tenants/$tid1" @{expires_at=(Get-Date).AddDays(30).ToString('yyyy-MM-dd HH:mm:ss')} $admin
Step '恢复有效期' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1'; password='tenant123'}
Step '恢复后可登录' ($r.token) ($r | ConvertTo-Json -Compress)
$tenantA = $r.token
$r = ApiPut "/api/admin/tenants/$tid1" @{status='banned'} $admin
$r = ApiPost '/api/auth/login' @{account='TESTKAMI1'; password='tenant123'}
Step '停用租户登录被拒' ($r.error -match '停用') ($r | ConvertTo-Json -Compress)
$r = ApiPut "/api/admin/tenants/$tid1" @{status='active'} $admin
Step '恢复启用' ($r.ok) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 16. 越权与鉴权 ==========" -ForegroundColor Cyan
$r = ApiGet '/api/admin/tenants' $tenantA
Step '租户访问 admin 接口被拒' ($r.error -match '无权限|未登录') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' 'invalid.token.value'
Step '无效 token 被拒(401)' ($r.error) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions'
Step '无 token 被拒(401)' ($r.error -match '未登录') ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='NOSUCHKAMI/x'; password='x'}
Step '不存在的子客服被拒' ($r.error) ($r | ConvertTo-Json -Compress)

Write-Host "`n========== 16.5 新功能做真（公告/过滤/去重/限频/清理/资源/日志） ==========" -ForegroundColor Cyan
$r = ApiPost '/api/admin/tenants' @{kami='TESTKAMI3'; password='tenant345'; nickname='租户C'; quota_web=20; quota_app=20; max_agents=2} $admin
Step '创建租户C（专测新功能）' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/auth/login' @{account='TESTKAMI3'; password='tenant345'}
Step '租户C登录' ($r.token) ($r | ConvertTo-Json -Compress)
$tenantC = $r.token
$r = ApiGet '/api/tenant/login-logs' $tenantC
Step '登录日志（>=1条）' (@($r).Count -ge 1 -and $r[0].role -eq 'tenant') ($r | ConvertTo-Json -Compress)

# --- 公告 ---
$r = ApiPut '/api/tenant/settings' @{notice_on=$true; notice_text='今日 20:00 直播福利'} $tenantC
Step '保存公告' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/visitor/config?t=TESTKAMI3'
Step '访客端读到公告' ($r.brand.notice_on -eq $true -and $r.brand.notice_text -match '直播') ($r | ConvertTo-Json -Compress)

# --- 关键词自动拉黑 ---
$r = ApiPut '/api/tenant/settings' @{filter_keywords='刷单,广告'; auto_blacklist=$true} $tenantC
Step '保存关键词过滤' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; name='访客C1'; visitor_token='vt_c1'}
$cid1 = $r.session_id
Step '访客C1进入' ($cid1) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/send' @{session_id=$cid1; text='请问可以刷单吗'}
Step '违规关键词消息被拒' ($r.error -match '违规') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/ip-blacklist' $tenantC
Step '自动拉黑生效（IP 在黑名单）' ((@($r) | Where-Object { $_.reason -match '关键词' }) -ne $null) ($r | ConvertTo-Json -Compress)
$r2 = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; visitor_token='vt_c1x'}
Step '拉黑后新访客被拒' ($r2.error -match '受限') ($r2 | ConvertTo-Json -Compress)
# 清空黑名单
$r = ApiPost '/api/tenant/cleanup' @{days=-1; scope='blacklist'} $tenantC
Step '清空黑名单' ($r.ok -and $r.deleted -ge 1) ($r | ConvertTo-Json -Compress)

# --- 访客去重 ---
$r = ApiPut '/api/tenant/settings' @{auto_blacklist=$false; dedup_on=$true} $tenantC
Step '开启访客去重' ($r.ok) ($r | ConvertTo-Json -Compress)
ApiPost "/api/sessions/$cid1/close" @{} $tenantC | Out-Null
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; visitor_token='vt_c1'}
Step '关闭后同token复用（去重生效）' ($r.reused -eq $true -and $r.session_id -eq $cid1) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $tenantC
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '去重复用未新建会话' (@($r).Count -eq 1) ($r | ConvertTo-Json -Compress)

# --- IP 频率限制（先清空会话使 60 秒窗口归零） ---
ApiPost '/api/tenant/cleanup' @{days=-1; scope='sessions'} $tenantC | Out-Null
$r = ApiPut '/api/tenant/settings' @{dedup_on=$false; ip_limit_on=$true; ip_limit_count=1} $tenantC
Step '开启IP限频(1/分钟)' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; name='访客C3'; visitor_token='vt_c3'}
Step '限频内第1个访客正常' ($r.session_id) ($r | ConvertTo-Json -Compress)
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; name='访客C4'; visitor_token='vt_c4'}
Step '限频触发拒绝' ($r.error -match '频繁') ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/ip-blacklist' $tenantC
Step '限频自动拉黑记录' ((@($r) | Where-Object { $_.reason -match '频率' }) -ne $null) ($r | ConvertTo-Json -Compress)
ApiPost '/api/tenant/cleanup' @{days=-1; scope='blacklist'} $tenantC | Out-Null
ApiPut '/api/tenant/settings' @{ip_limit_on=$false} $tenantC | Out-Null
Step '关闭限频并清黑名单' ($true) ('')

# --- 访客资源 ---
$r = ApiPost '/api/visitor/init' @{t='TESTKAMI3'; name='访客C5'; visitor_token='vt_c5'; phone='13900001111'}
$cid5 = $r.session_id
ApiPut "/api/sessions/$cid5/contacts/wechat" @{value='wx_c5'} $tenantC | Out-Null
ApiPost '/api/visitor/send' @{session_id=$cid5; text='你好呀'} | Out-Null
$r = ApiGet '/api/tenant/visitors' $tenantC
Step '访客资源聚合（含联系方式）' (@($r).Count -ge 2 -and (@($r) | Where-Object { $_.mobile -eq '13900001111' }) -ne $null) ($r | ConvertTo-Json -Compress)

# --- 数据清理 ---
$r = ApiPost '/api/tenant/cleanup' @{days=-1; scope='sessions'} $tenantC
Step '清理全部会话' ($r.ok -and $r.deleted -ge 2) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/sessions' $tenantC
$r = $r.sessions   # v2.25 响应包装 {sessions, counts}
Step '清理后无会话' (@($r).Count -eq 0) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/tenant/visitors' $tenantC
Step '清理后访客资源为空' (@($r).Count -eq 0) ($r | ConvertTo-Json -Compress)

# 收尾：删除租户C
$r = ApiGet '/api/admin/tenants' $admin
$tidC = (@($r) | Where-Object { $_.kami -eq 'TESTKAMI3' }).id
ApiDel "/api/admin/tenants/$tidC" $admin | Out-Null
Step '删除租户C' ($true) ('')

Write-Host "`n========== 17. 管理员概览 + 级联删除 ==========" -ForegroundColor Cyan
$r = ApiGet '/api/admin/overview' $admin
Step '平台概览（统计+排行）' ($r.tenants_total -ge 2 -and @($r.ranking).Count -ge 1 -and $r.sessions_total -ge 4) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/admin/tenants' $admin
$tidB = (@($r) | Where-Object { $_.kami -eq $kamiB }).id
$r = ApiDel "/api/admin/tenants/$tidB" $admin
Step '删除租户B（级联）' ($r.ok) ($r | ConvertTo-Json -Compress)
$r = ApiGet '/api/admin/tenants' $admin
Step '租户B已删除' (((@($r) | Where-Object { $_.nickname -eq '租户B' }) | Measure-Object).Count -eq 0) (ConvertTo-Json @($r) -Compress)
$r = ApiPost '/api/auth/login' @{account=$kamiB; password='tenant234'}
Step '已删租户登录被拒' ($r.error -match '不存在') ($r | ConvertTo-Json -Compress)

Write-Host "`n================================================" -ForegroundColor Cyan
RestoreEnv
$color = 'Yellow'; if ($fail -eq 0) { $color = 'Green' }
Write-Host "PASS: $pass  FAIL: $fail" -ForegroundColor $color

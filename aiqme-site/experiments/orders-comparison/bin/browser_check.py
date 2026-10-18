#!/usr/bin/env python3
"""Uses bundled Playwright Chromium, not the user's Chrome/CDP profile."""
from pathlib import Path
import json,subprocess
from playwright.sync_api import sync_playwright
root=Path(__file__).resolve().parents[1];evidence=root/'evidence';results={}
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True)
 for variant,port in [('lunar-aiqme',8857),('laravel-orders-aiqme',8858)]:
  context=browser.new_context(viewport={'width':1440,'height':1000});page=context.new_page();errors=[]
  page.on('pageerror',lambda error:errors.append(str(error)))
  base=f'http://127.0.0.1:{port}'
  def login(email):
   page.goto(base);page.locator('input[name=email]').fill(email);page.locator('input[name=password]').fill('local-probe-only');page.get_by_role('button',name='Sign in').click();page.wait_for_url('**/comparison/orders')
  login('buyer@example.test');page.get_by_role('button',name='New STL basket').click()
  for name,material in [('bracket','PLA'),('spacer','PETG')]:
   page.locator('input[name=file]').set_input_files(root.parent/variant/'app/fixtures'/f'{name}.stl');page.locator('select[name=material]').select_option(material);page.get_by_role('button',name='Add STL').click()
  assert page.locator('table tr').count()==3
  assert '12000' in page.locator('main').inner_text()
  page.screenshot(path=evidence/f'{variant}-basket.png',full_page=True)
  page.get_by_role('button',name='Submit request without payment').click();order_url=page.url
  assert '12000' in page.locator('main').inner_text()
  context.clear_cookies();login('admin@example.test');page.goto(order_url)
  prices=page.locator('input[name^="prices["]');prices.nth(0).fill('6000');prices.nth(1).fill('9000');page.locator('input[name=reason]').fill('Browser review');page.locator('input[name=agreement]').fill('synthetic-browser-agreement');page.get_by_role('button',name='Confirm',exact=True).click()
  assert '15000' in page.locator('main').inner_text()
  page.get_by_role('button',name='Start production independently of payment').click()
  assert 'in-production' in page.locator('main').inner_text() and 'unpaid' in page.locator('main').inner_text()
  page.screenshot(path=evidence/f'{variant}-unpaid-production.png',full_page=True)
  fixture=subprocess.check_output([str(root.parent/'lunar-aiqme/bin/php'),'artisan','comparison:demo'],cwd=root.parent/variant/'app',text=True)
  (evidence/f'{variant}-browser-fixture.jsonl').write_text(fixture)
  fixture_id=json.loads(fixture.splitlines()[-1])['order_id']
  page.goto(base+'/orders/'+str(fixture_id));assert 'partial' in page.locator('main').inner_text();assert '11000' in page.locator('main').inner_text()
  with page.expect_download() as download:page.get_by_role('link',name='bracket.stl',exact=True).click()
  assert download.value.suggested_filename=='bracket.stl'
  page.screenshot(path=evidence/f'{variant}-partial-order.png',full_page=True)
  assert not errors,errors
  results[variant]={'upload_two_stl':'pass','submit_without_payment':'pass','admin_confirms_price':'pass','start_unpaid':'pass','partial_balance_display':'pass','private_download':'pass','page_errors':errors,'browser':'isolated bundled Chromium; user Chrome untouched'}
  context.close()
 browser.close()
(evidence/'browser-results.json').write_text(json.dumps(results,indent=2));print(json.dumps(results,indent=2))

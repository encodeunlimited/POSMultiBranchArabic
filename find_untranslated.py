import re
with open('public/lang.php', 'r', encoding='utf-8') as f:
    content = f.read()
matches = re.findall(r"'([^']+)'\s*=>\s*'([^']+)'", content)
for k, v in matches:
    if k == v:
        print(f"\"{k}\": \"\",")

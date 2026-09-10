import re
import glob
import json

keys = set()

for file in glob.glob("templates/*.twig"):
    with open(file, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # find all __("something") or __('something')
    matches = re.findall(r"__\('([^']+)'\)", content)
    for m in matches:
        keys.add(m)
        
    matches2 = re.findall(r'__\("([^"]+)"\)', content)
    for m in matches2:
        keys.add(m)

with open('keys.json', 'w', encoding='utf-8') as f:
    json.dump(list(keys), f, indent=2)

import re
import glob

def process_file(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content

    def is_safe(text):
        if not text: return False
        if "__(" in text or "{{" in text or "}}" in text or "{%" in text or "%}" in text: return False
        if "$" in text or "@media" in text or "lucide." in text or "function" in text: return False
        if "console.log" in text or "document." in text or "window." in text: return False
        if text.strip().startswith("${"): return False
        if "=>" in text or "px" in text or "rem" in text: return False
        # Must contain at least one letter
        if not re.search(r'[A-Za-z]', text): return False
        return True

    # Replace text nodes
    def safe_text_replace(match):
        text = match.group(1).strip()
        if not is_safe(text):
            return match.group(0)
            
        escaped_text = text.replace("'", "\\'")
        replacement = f"{{{{ __('{escaped_text}') }}}}"
        return match.group(0).replace(text, replacement)

    content = re.sub(r'>([^<]+)<', safe_text_replace, content)

    # Replace placeholders
    def safe_placeholder_replace(match):
        text = match.group(1)
        if not is_safe(text): return match.group(0)
        escaped_text = text.replace("'", "\\'")
        return f'placeholder="{{{{ __(\'{escaped_text}\') }}}}"'
        
    content = re.sub(r'placeholder="([^"]+)"', safe_placeholder_replace, content)

    # Replace titles
    def safe_title_replace(match):
        text = match.group(1)
        if not is_safe(text): return match.group(0)
        if "\n" in match.group(0): return match.group(0)
        escaped_text = text.replace("'", "\\'")
        return f'title="{{{{ __(\'{escaped_text}\') }}}}"'
        
    content = re.sub(r'title="([^"]+)"', safe_title_replace, content)

    if content != original_content:
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"Updated {filepath}")

for file in glob.glob("templates/*.twig"):
    process_file(file)

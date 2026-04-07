import os, shutil

ROOT = os.path.dirname(os.path.abspath(__file__))
PUB  = os.path.join(ROOT, "public", "pret")
targets = [
    os.path.join(PUB, "maps"),
    os.path.join(PUB, "tilesets"),
]

print("[PRET] Clearing generated cache folders...")

for t in targets:
    try:
        shutil.rmtree(t)
    except FileNotFoundError:
        pass
    except Exception as e:
        print("WARN:", t, e)

for t in targets:
    os.makedirs(t, exist_ok=True)

print("Done. Now refresh browser with Ctrl+F5.")
input("Press Enter to exit...")

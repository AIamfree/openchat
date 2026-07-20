from fastapi import FastAPI, Query
import g4f

app = FastAPI()

@app.get("/")
def root():
    return {"message": "G4F API is running on Render!"}

@app.get("/chat")
def chat(prompt: str = Query(..., description="User prompt")):
    try:
        response = g4f.ChatCompletion.create(
            model="gpt-4",
            messages=[{"role": "user", "content": prompt}]
        )
        return {"reply": response}
    except Exception as e:
        return {"error": str(e)}

"""
SmartCare Guardian — Flask API for Health Risk Prediction

This API receives health vital signs from PHP and returns AI predictions.

Endpoints:
  GET  /              - API health check
  POST /predict       - Get risk prediction for single reading
  POST /predict/batch - Get predictions for multiple readings
  GET  /model/info    - Get model metadata and performance
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
import pandas as pd
import numpy as np
import joblib
import json
import os
from datetime import datetime

# ============================================================
# INITIALIZE FLASK APP
# ============================================================

app = Flask(__name__)
CORS(app)  # Allow PHP to call this API from different domain

# ============================================================
# LOAD MODEL AND PREPROCESSOR AT STARTUP
# ============================================================

print("=" * 60)
print("SmartCare Guardian AI API Starting...")
print("=" * 60)

# Load model metadata
try:
    with open('models/model_metadata.json', 'r') as f:
        MODEL_METADATA = json.load(f)
    print(f"\n✓ Loaded model metadata")
    print(f"  Model: {MODEL_METADATA['model_name']}")
    print(f"  Trained: {MODEL_METADATA['train_date']}")
    print(f"  Test Accuracy: {MODEL_METADATA['test_accuracy']:.4f}")
    print(f"  Test Recall: {MODEL_METADATA['test_recall']:.4f}")
except Exception as e:
    print(f"⚠ Warning: Could not load model metadata: {e}")
    MODEL_METADATA = {
        'model_name': 'Unknown',
        'model_type': 'unknown',
        'features': [],
        'test_accuracy': 0,
        'test_recall': 0,
        'test_auc': 0,
        'train_date': 'Unknown'
    }

# Load the model
try:
    model_type = MODEL_METADATA.get('model_type', 'tree')
    
    if model_type == 'ann':
        # Load Keras model
        from tensorflow import keras
        MODEL = keras.models.load_model('models/best_model.keras')
        print(f"✓ Loaded ANN model from models/best_model.keras")
    else:
        # Load scikit-learn/xgboost model
        MODEL = joblib.load('models/best_model.pkl')
        print(f"✓ Loaded {MODEL_METADATA['model_name']} model from models/best_model.pkl")
    
    # Load preprocessor
    PREPROCESSOR = joblib.load('models/preprocessor.pkl')
    print(f"✓ Loaded preprocessor from models/preprocessor.pkl")
    
    # Expected features (in correct order)
    EXPECTED_FEATURES = MODEL_METADATA.get('features', [
        'blood_pressure_systolic', 'blood_pressure_diastolic',
        'blood_sugar', 'pulse', 'weight',
        'temperature', 'oxygen_saturation',
        'hour', 'day_of_week', 'month'
    ])
    
    print(f"✓ Expected features ({len(EXPECTED_FEATURES)}): {EXPECTED_FEATURES}")
    print("\n" + "=" * 60)
    print("API Ready to receive requests!")
    print("=" * 60 + "\n")
    
except Exception as e:
    print(f"\n❌ ERROR: Failed to load model or preprocessor")
    print(f"   {str(e)}")
    print(f"\nMake sure you've trained the model first using train_models.ipynb")
    exit(1)


# ============================================================
# HELPER FUNCTIONS
# ============================================================

def add_time_features(data):
    """
    Add time-based features from logged_at timestamp.
    
    Args:
        data: dict with 'logged_at' key (format: 'YYYY-MM-DD HH:MM:SS')
    
    Returns:
        dict with added hour, day_of_week, month
    """
    try:
        if 'logged_at' in data and data['logged_at']:
            dt = pd.to_datetime(data['logged_at'])
        else:
            # If no timestamp provided, use current time
            dt = datetime.now()
        
        data['hour']        = dt.hour
        data['day_of_week'] = dt.dayofweek   # 0=Monday, 6=Sunday
        data['month']       = dt.month
        
    except Exception as e:
        # Fallback to current time if parsing fails
        dt = datetime.now()
        data['hour']        = dt.hour
        data['day_of_week'] = dt.dayofweek
        data['month']       = dt.month
    
    return data


def prepare_features(data):
    """
    Prepare input data for prediction.
    
    Args:
        data: dict with vital sign values
    
    Returns:
        pandas DataFrame with features in correct order
    """
    # Add time features
    data = add_time_features(data)
    
    # Create DataFrame with expected features
    df = pd.DataFrame([data])
    
    # Select only the features the model expects (in correct order)
    df = df[EXPECTED_FEATURES]
    
    return df


def make_prediction(features_df):
    """
    Make risk prediction using loaded model.
    
    Args:
        features_df: DataFrame with preprocessed features
    
    Returns:
        dict with prediction results
    """
    # Preprocess features
    features_processed = PREPROCESSOR.transform(features_df)
    
    # Make prediction
    if MODEL_METADATA.get('model_type') == 'ann':
        # ANN returns probability directly
        risk_probability = float(MODEL.predict(features_processed, verbose=0)[0][0])
        risk_prediction = 1 if risk_probability > 0.5 else 0
    else:
        # Tree models (RF, XGBoost)
        risk_prediction = int(MODEL.predict(features_processed)[0])
        risk_probability = float(MODEL.predict_proba(features_processed)[0][1])
    
    # Determine risk level
    if risk_probability >= 0.7:
        risk_level = 'high'
    elif risk_probability >= 0.4:
        risk_level = 'medium'
    else:
        risk_level = 'low'
    
    return {
        'risk_prediction': risk_prediction,      # 0 or 1
        'risk_probability': round(risk_probability, 4),  # 0.0 to 1.0
        'risk_level': risk_level,                # low/medium/high
        'risk_percentage': round(risk_probability * 100, 2)  # percentage
    }


def generate_alert_message(data, prediction):
    """
    Generate human-readable alert message based on vital signs.
    
    Args:
        data: dict with vital sign values
        prediction: dict with prediction results
    
    Returns:
        dict with alert information
    """
    alerts = []
    
    # Check each vital sign against normal ranges
    vitals_checks = [
        ('blood_pressure_systolic', 90, 140, 'Blood Pressure Systolic', 'mmHg'),
        ('blood_pressure_diastolic', 60, 90, 'Blood Pressure Diastolic', 'mmHg'),
        ('blood_sugar', 70, 126, 'Blood Sugar', 'mg/dL'),
        ('pulse', 60, 100, 'Pulse', 'bpm'),
        ('temperature', 36.0, 37.8, 'Temperature', '°C'),
        ('oxygen_saturation', 95, 100, 'Oxygen Saturation', '%'),
    ]
    
    for vital, min_val, max_val, display_name, unit in vitals_checks:
        if vital in data:
            value = data[vital]
            
            if value < min_val:
                alerts.append({
                    'vital_sign': display_name,
                    'value': value,
                    'unit': unit,
                    'status': 'LOW',
                    'normal_range': f'{min_val}-{max_val} {unit}',
                    'message': f'{display_name} is {value} {unit} (below normal range)'
                })
            elif value > max_val:
                alerts.append({
                    'vital_sign': display_name,
                    'value': value,
                    'unit': unit,
                    'status': 'HIGH',
                    'normal_range': f'{min_val}-{max_val} {unit}',
                    'message': f'{display_name} is {value} {unit} (above normal range)'
                })
    
    return {
        'total_alerts': len(alerts),
        'alerts': alerts,
        'requires_attention': prediction['risk_prediction'] == 1 or len(alerts) > 0
    }


# ============================================================
# API ENDPOINTS
# ============================================================

@app.route('/', methods=['GET'])
def home():
    """API health check endpoint."""
    return jsonify({
        'status': 'success',
        'message': 'SmartCare Guardian AI API is running',
        'version': '1.0.0',
        'model': MODEL_METADATA['model_name'],
        'endpoints': {
            '/': 'GET - API health check',
            '/predict': 'POST - Get risk prediction for single reading',
            '/predict/batch': 'POST - Get predictions for multiple readings',
            '/model/info': 'GET - Get model information and performance metrics'
        }
    }), 200


@app.route('/model/info', methods=['GET'])
def model_info():
    """Get model metadata and performance metrics."""
    return jsonify({
        'status': 'success',
        'data': MODEL_METADATA
    }), 200


@app.route('/predict', methods=['POST'])
def predict():
    """
    Predict health risk for a single reading.
    
    Expected JSON body:
    {
        "resident_id": 123,
        "blood_pressure_systolic": 145,
        "blood_pressure_diastolic": 95,
        "blood_sugar": 160,
        "pulse": 88,
        "weight": 72.5,
        "temperature": 37.2,
        "oxygen_saturation": 96,
        "logged_at": "2024-01-15 08:30:00"  (optional)
    }
    
    Returns:
    {
        "status": "success",
        "data": {
            "resident_id": 123,
            "risk_prediction": 1,
            "risk_probability": 0.8234,
            "risk_level": "high",
            "risk_percentage": 82.34,
            "alerts": [...],
            "timestamp": "2024-01-15 08:30:15"
        }
    }
    """
    try:
        # Get JSON data from request
        data = request.get_json()
        
        if not data:
            return jsonify({
                'status': 'error',
                'message': 'No data provided'
            }), 400
        
        # Extract resident_id if provided
        resident_id = data.get('resident_id', None)
        
        # Prepare features
        features_df = prepare_features(data)
        
        # Make prediction
        prediction = make_prediction(features_df)
        
        # Generate alert messages
        alert_info = generate_alert_message(data, prediction)
        
        # Build response
        response = {
            'status': 'success',
            'data': {
                'resident_id': resident_id,
                'prediction': prediction,
                'alerts': alert_info,
                'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'model_used': MODEL_METADATA['model_name']
            }
        }
        
        return jsonify(response), 200
    
    except Exception as e:
        return jsonify({
            'status': 'error',
            'message': f'Prediction failed: {str(e)}',
            'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }), 500


@app.route('/predict/batch', methods=['POST'])
def predict_batch():
    """
    Predict health risk for multiple readings at once.
    
    Expected JSON body:
    {
        "readings": [
            {
                "resident_id": 123,
                "blood_pressure_systolic": 145,
                ...
            },
            {
                "resident_id": 124,
                "blood_pressure_systolic": 118,
                ...
            }
        ]
    }
    
    Returns predictions for all readings in a list.
    """
    try:
        data = request.get_json()
        
        if not data or 'readings' not in data:
            return jsonify({
                'status': 'error',
                'message': 'Expected JSON with "readings" array'
            }), 400
        
        readings = data['readings']
        predictions = []
        
        for reading in readings:
            try:
                # Prepare features
                features_df = prepare_features(reading)
                
                # Make prediction
                prediction = make_prediction(features_df)
                
                # Generate alert messages
                alert_info = generate_alert_message(reading, prediction)
                
                predictions.append({
                    'resident_id': reading.get('resident_id', None),
                    'prediction': prediction,
                    'alerts': alert_info
                })
            
            except Exception as e:
                predictions.append({
                    'resident_id': reading.get('resident_id', None),
                    'error': str(e)
                })
        
        return jsonify({
            'status': 'success',
            'total_predictions': len(predictions),
            'data': predictions,
            'timestamp': datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        }), 200
    
    except Exception as e:
        return jsonify({
            'status': 'error',
            'message': f'Batch prediction failed: {str(e)}'
        }), 500


# ============================================================
# RUN THE API
# ============================================================

if __name__ == '__main__':
    print("\n" + "=" * 60)
    print("Starting Flask API Server...")
    print("API will be available at: http://127.0.0.1:5000")
    print("=" * 60 + "\n")
    
    # Run Flask app
    # debug=True for development, set to False in production
    app.run(host='127.0.0.1', port=5000, debug=True)
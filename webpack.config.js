var path = require('path');
var TerserPlugin = require('terser-webpack-plugin');

module.exports = {
  mode: process.env.NODE_ENV,
  entry: {
    'endereco': './endereco.js',
  },
  output: {
    path: path.resolve(__dirname, './frontend/js/'),
    publicPath: '/',
    filename: 'endereco.min.js'
  },
  optimization: {
    minimize: true,
    minimizer: [new TerserPlugin({
      terserOptions: {
        output: {
          comments: false,
        },
      },
      extractComments: false,
    })],
  },
  module: {
    rules: [
      {
        test: /\.css$/,
        use: [
          {
            loader: 'css-loader',
            options: {
              exportType: 'array',
              esModule: false,
            },
          },
        ],
      },
      {
        test: /\.scss$/,
        use: [
          {
            loader: 'css-loader',
            options: {
              exportType: 'array',
              esModule: false,
            },
          },
          'sass-loader'
        ],
      },
      {
        test: /\.html$/,
        use: {loader: 'html-loader'}
      },
      {
        test: /\.js$/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: ['@babel/preset-env']
          }
        }
      },
      {
        test: /\.(png|jpg|gif)$/,
        type: 'asset/resource',
        generator: {
          filename: '[name][ext]?[hash]'
        }
      },
      {
        test: /\.svg$/,
        use: {loader: 'html-loader'}
      }
    ]
  },
  performance: {
    hints: false
  },
  devtool: false,
  plugins: [
  ]
};
